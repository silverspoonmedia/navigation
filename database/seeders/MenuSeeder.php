<?php

namespace App\Domain\Navigation\Database\Seeders;

use App\Domain\Identity\Models\Role;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Support\MenuLayout;
use App\Support\Navigation\MenuContributorRegistry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rebuilds the canonical dynamic navigation tree.
 *
 * This seeder is intentionally authoritative: every run replaces all active and
 * trashed menu rows so existing installations converge on the same UX tree.
 *
 * The tree's **shape** is the package's (workspace / products / administration);
 * the nodes a product owns arrive as {@see MenuContributor} registrations and are
 * merged by node key. With no product installed the same code seeds the core tree
 * and an empty group is dropped, so the package names no product.
 */
class MenuSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var array<string, string> */
    private array $roleIds = [];

    public function run(): void
    {
        $this->roleIds = Role::query()
            ->where('guard_name', 'api')
            ->pluck('id', 'name')
            ->all();

        $contributions = $this->contributions();

        DB::transaction(function () use ($contributions): void {
            Menu::withTrashed()->forceDelete();

            $this->seedTree($this->mergeContributions($this->sidebarTree(), $contributions), MenuLayout::SIDEBAR);
            $this->seedTree($this->mergeContributions($this->navbarTree(), $contributions), MenuLayout::NAVBAR);
        });

        app(MenuRepositoryInterface::class)->flushNavigationCache();
    }

    /**
     * Contributions grouped by target node key, or an empty map on a bare host.
     *
     * @return array<string, list<array{nodes: list<array<string, mixed>>, after: string|null}>>
     */
    private function contributions(): array
    {
        if (! app()->bound(MenuContributorRegistry::class)) {
            return [];
        }

        return app(MenuContributorRegistry::class)->contributions();
    }

    /**
     * Insert every contribution under the node its target key names.
     *
     * A contribution lands after the sibling key its `after` names — including a
     * node another contribution just inserted — or at the end of the group.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, list<array{nodes: list<array<string, mixed>>, after: string|null}>>  $contributions
     * @return list<array<string, mixed>>
     */
    private function mergeContributions(array $nodes, array $contributions, string $prefix = ''): array
    {
        $merged = [];

        foreach ($nodes as $node) {
            $path = $this->nodePath($prefix, $node);
            $children = $node['children'] ?? [];

            if ($children !== []) {
                $node['children'] = $this->mergeContributions($children, $contributions, $path ?? '');
            }

            if ($path !== null && isset($contributions[$path])) {
                $node['children'] = $this->applyContribution($node['children'] ?? [], $contributions[$path]);
            }

            $merged[] = $node;
        }

        // A group whose only purpose was product placement collapses to nothing.
        return array_values(array_filter(
            $merged,
            fn (array $node): bool => ! ($node['drop_when_empty'] ?? false) || ($node['children'] ?? []) !== [],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  list<array{nodes: list<array<string, mixed>>, after: string|null}>  $entries
     * @return list<array<string, mixed>>
     */
    private function applyContribution(array $children, array $entries): array
    {
        foreach ($entries as $entry) {
            $anchorKey = $entry['after'];

            if ($anchorKey === null) {
                $children = [...$children, ...$entry['nodes']];

                continue;
            }

            $position = $this->indexOfKey($children, $anchorKey);

            if ($position === null) {
                $children = [...$children, ...$entry['nodes']];

                continue;
            }

            array_splice($children, $position + 1, 0, $entry['nodes']);
        }

        return $children;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function indexOfKey(array $nodes, string $key): ?int
    {
        foreach ($nodes as $index => $node) {
            if (($node['key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nodePath(string $prefix, array $node): ?string
    {
        $key = $node['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $prefix === '' ? $key : $prefix.'.'.$key;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function seedTree(array $nodes, string $layout, ?string $parentId = null): void
    {
        foreach (array_values($nodes) as $index => $node) {
            $children = $node['children'] ?? [];
            $roleNames = $node['roles'] ?? [];

            $menu = Menu::query()->create([
                'parent_id' => $parentId,
                'layout' => $layout,
                'name' => $node['name'],
                'description' => $node['description'] ?? null,
                'icon' => $node['icon'] ?? null,
                'route' => $node['route'] ?? null,
                'hash' => $node['hash'] ?? null,
                'type' => $node['type'],
                'order' => $index + 1,
                // The audience is derived from the declared roles so a seeded
                // node can never carry role pivots under a non-role audience.
                'audience' => $node['audience'] ?? ($roleNames === [] ? MenuAudience::Auth : MenuAudience::Role),
            ]);
 
            if ($roleNames !== []) {
                $menu->roles()->sync($this->idsForRoles($roleNames));
            }

            if ($children !== []) {
                $this->seedTree($children, $layout, $menu->id);
            }
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    private function idsForRoles(array $roleNames): array
    {
        $missing = array_values(array_filter(
            $roleNames,
            fn (string $roleName): bool => ! isset($this->roleIds[$roleName]),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'MenuSeeder references unseeded role(s): '.implode(', ', array_unique($missing))
                .'. Seed RoleSeeder and the product role seeders (e.g. the product that owns the contributed node) before MenuSeeder.'
            );
        }

        return array_values(array_map(
            fn (string $roleName): string => $this->roleIds[$roleName],
            $roleNames,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sidebarTree(): array
    {
        $managementRoles = ['Root', 'Admin', 'Moderator', 'Management'];
        $adminRoles = ['Root', 'Admin'];

        return [
            [
                'key' => 'workspace',
                'name' => $this->translations('Workspace', 'Ruang Kerja', 'ワークスペース'),
                'type' => 'h',
                'children' => [
                    [
                        'key' => 'workspace.dashboard',
                        'name' => $this->translations('Dashboard', 'Beranda', 'ダッシュボード'),
                        'icon' => 'bi bi-speedometer2',
                        'route' => 'dashboard',
                        'type' => 'p',
                    ],
                    [
                        'key' => 'workspace.notifications',
                        'name' => $this->translations('Notifications', 'Notifikasi', '通知'),
                        'icon' => 'bi bi-bell',
                        'route' => 'notifications.index',
                        'type' => 'p',
                    ],
                    [
                        'key' => 'workspace.services',
                        'name' => $this->translations('Services', 'Layanan', 'サービス'),
                        'icon' => 'bi bi-box-seam',
                        'route' => 'services.index',
                        'type' => 'p',
                    ],
                ],
            ],
            [
                // Products is a placement group: a product domain contributes its
                // nodes here (see MenuContributor); with none installed it drops.
                'key' => 'products',
                'name' => $this->translations('Products', 'Produk', 'プロダクト'),
                'type' => 'h',
                'drop_when_empty' => true,
                'children' => [],
            ],
            [
                'key' => 'administration',
                'name' => $this->translations('Administration', 'Administrasi', '管理'),
                'type' => 'h',
                'children' => [
                    [
                        'key' => 'administration.access-control',
                        'name' => $this->translations('Access control', 'Kontrol akses', 'アクセス管理'),
                        'icon' => 'bi bi-people',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.access-control.users',
                                'name' => $this->translations('User management', 'Manajemen Pengguna', 'ユーザー管理'),
                                'icon' => 'bi bi-people',
                                'route' => 'users.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.access-control.activations',
                                'name' => $this->translations('Product activations', 'Aktivasi Produk', 'プロダクト有効化'),
                                'icon' => 'bi bi-toggle-on',
                                'route' => 'product-activations.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.access-control.domain-access',
                                'name' => $this->translations('Product domain access', 'Akses Domain Produk', 'プロダクトドメインアクセス'),
                                'icon' => 'bi bi-shield-lock',
                                'route' => 'domain-access.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.payments',
                        'name' => $this->translations('Payments', 'Pembayaran', '決済'),
                        'icon' => 'bi bi-credit-card',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.payments.income',
                                'name' => $this->translations('Payment income', 'Pendapatan pembayaran', '決済収入'),
                                'icon' => 'bi bi-graph-up-arrow',
                                'route' => 'payments.income.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.invoices',
                                'name' => $this->translations('Invoices', 'Invoice', '請求書'),
                                'icon' => 'bi bi-receipt',
                                'route' => 'invoices.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.subscriptions',
                                'name' => $this->translations('Subscriptions', 'Langganan', 'サブスクリプション'),
                                'icon' => 'bi bi-arrow-repeat',
                                'route' => 'subscriptions.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.channels',
                                'name' => $this->translations('Payment channels', 'Channel pembayaran', '決済チャネル'),
                                'icon' => 'bi bi-credit-card-2-front',
                                'route' => 'payment-channels.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.payments.gateways',
                                'name' => $this->translations('Payment gateways', 'Payment gateway', '決済ゲートウェイ'),
                                'icon' => 'bi bi-diagram-3',
                                'route' => 'payment-gateways.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.inference',
                        'name' => $this->translations('Inference', 'Inferensi', '推論'),
                        'icon' => 'bi bi-cpu',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.inference.model-cards',
                                'name' => $this->translations('Model catalog', 'Katalog model', 'モデルカタログ'),
                                'icon' => 'bi bi-boxes',
                                'route' => 'inference.model-cards.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.inference.providers',
                                'name' => $this->translations('Providers', 'Provider', 'プロバイダー'),
                                'icon' => 'bi bi-hdd-network',
                                'route' => 'inference.providers.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.inference.usage-events',
                                'name' => $this->translations('Usage events', 'Event penggunaan', '利用イベント'),
                                'icon' => 'bi bi-graph-up',
                                'route' => 'inference.usage-events.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.ai-connections',
                        'name' => $this->translations('AI connections', 'Koneksi AI', 'AI 接続'),
                        'icon' => 'bi bi-plug',
                        'route' => 'ai.connections.index',
                        'type' => 'p',
                        'roles' => $managementRoles,
                    ],
                    [
                        'key' => 'administration.monitoring',
                        'name' => $this->translations('Monitoring', 'Pemantauan', '監視'),
                        'icon' => 'bi bi-activity',
                        'type' => 'p',
                        'roles' => $adminRoles,
                        'children' => [
                            [
                                'key' => 'administration.monitoring.activity-log',
                                'name' => $this->translations('Activity log', 'Log Aktivitas', 'アクティビティログ'),
                                'icon' => 'bi bi-journal-text',
                                'route' => 'activity-logs.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'key' => 'administration.monitoring.error-log',
                                'name' => $this->translations('Error log', 'Log Error', 'エラーログ'),
                                'icon' => 'bi bi-bug',
                                'route' => 'error-logs.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'settings',
                        'name' => $this->translations('Settings', 'Pengaturan', '設定'),
                        'icon' => 'bi bi-gear',
                        'type' => 'p',
                        'roles' => $adminRoles,
                        'children' => [
                            [
                                'key' => 'settings.platform',
                                'name' => $this->translations('Platform', 'Platform', 'プラットフォーム'),
                                'icon' => 'bi bi-sliders',
                                'route' => 'settings.platform',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'key' => 'settings.notification-audiences',
                                'name' => $this->translations('Notification audiences', 'Audiens notifikasi', '通知オーディエンス'),
                                'icon' => 'bi bi-bell',
                                'route' => 'notification-audiences.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'key' => 'settings.currencies',
                                'name' => $this->translations('Currencies', 'Mata uang', '通貨'),
                                'icon' => 'bi bi-currency-exchange',
                                'route' => 'currencies.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'key' => 'administration.navigation',
                        'name' => $this->translations('Navigation', 'Navigasi', 'ナビゲーション'),
                        'icon' => 'bi bi-list-nested',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'key' => 'administration.navigation.menu-list',
                                'name' => $this->translations('Menu list', 'Daftar Menu', 'メニュー一覧'),
                                'icon' => 'bi bi-list-ul',
                                'route' => 'menus.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'key' => 'administration.navigation.trashed',
                                'name' => $this->translations('Trashed menus', 'Menu Terhapus', '削除済みメニュー'),
                                'icon' => 'bi bi-trash',
                                'route' => 'menus.trashed',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navbarTree(): array
    {
        return [
            [
                'name' => $this->translations('Home', 'Beranda', 'ホーム'),
                'icon' => 'bi bi-house',
                'route' => 'dashboard',
                'type' => 'p',
            ],
        ];
    }

    /**
     * @return array{en: string, id: string, ja: string, ko: string, zh_CN: string, ar: string, ru: string}
     */
    private function translations(string $english, string $indonesian, string $japanese): array
    {
        $additional = [
            'Workspace' => [
                'ko' => '작업 공간',
                'zh_CN' => '工作区',
                'ar' => 'مساحة العمل',
                'ru' => 'Рабочая область',
            ],
            'Dashboard' => [
                'ko' => '대시보드',
                'zh_CN' => '控制面板',
                'ar' => 'لوحة التحكم',
                'ru' => 'Панель управления',
            ],
            'Notifications' => [
                'ko' => '알림',
                'zh_CN' => '通知',
                'ar' => 'الإشعارات',
                'ru' => 'Уведомления',
            ],
            'Services' => [
                'ko' => '서비스',
                'zh_CN' => '服务',
                'ar' => 'الخدمات',
                'ru' => 'Сервисы',
            ],
            'Products' => [
                'ko' => '제품',
                'zh_CN' => '产品',
                'ar' => 'المنتجات',
                'ru' => 'Продукты',
            ],
            'Administration' => [
                'ko' => '관리',
                'zh_CN' => '管理',
                'ar' => 'الإدارة',
                'ru' => 'Администрирование',
            ],
            'Access control' => [
                'ko' => '접근 제어',
                'zh_CN' => '访问控制',
                'ar' => 'التحكم بالوصول',
                'ru' => 'Управление доступом',
            ],
            'User management' => [
                'ko' => '사용자 관리',
                'zh_CN' => '用户管理',
                'ar' => 'إدارة المستخدمين',
                'ru' => 'Управление пользователями',
            ],
            'Product activations' => [
                'ko' => '제품 활성화',
                'zh_CN' => '产品激活',
                'ar' => 'تفعيلات المنتجات',
                'ru' => 'Активации продуктов',
            ],
            'Product domain access' => [
                'ko' => '제품 도메인 접근',
                'zh_CN' => '产品域访问',
                'ar' => 'الوصول إلى نطاق المنتج',
                'ru' => 'Доступ к доменам продуктов',
            ],
            'Payments' => [
                'ko' => '결제',
                'zh_CN' => '支付',
                'ar' => 'المدفوعات',
                'ru' => 'Платежи',
            ],
            'Payment income' => [
                'ko' => '결제 수입',
                'zh_CN' => '支付收入',
                'ar' => 'إيرادات المدفوعات',
                'ru' => 'Доход от платежей',
            ],
            'Invoices' => [
                'ko' => '청구서',
                'zh_CN' => '发票',
                'ar' => 'الفواتير',
                'ru' => 'Счета',
            ],
            'Subscriptions' => [
                'ko' => '구독',
                'zh_CN' => '订阅',
                'ar' => 'الاشتراكات',
                'ru' => 'Подписки',
            ],
            'Payment channels' => [
                'ko' => '결제 채널',
                'zh_CN' => '支付渠道',
                'ar' => 'قنوات الدفع',
                'ru' => 'Платёжные каналы',
            ],
            'Payment gateways' => [
                'ko' => '결제 게이트웨이',
                'zh_CN' => '支付网关',
                'ar' => 'بوابات الدفع',
                'ru' => 'Платёжные шлюзы',
            ],
            'Inference' => [
                'ko' => '추론',
                'zh_CN' => '推理',
                'ar' => 'الاستدلال',
                'ru' => 'Инференс',
            ],
            'Model catalog' => [
                'ko' => '모델 카탈로그',
                'zh_CN' => '模型目录',
                'ar' => 'كتالوج النماذج',
                'ru' => 'Каталог моделей',
            ],
            'Providers' => [
                'ko' => '제공업체',
                'zh_CN' => '服务提供商',
                'ar' => 'المزوّدون',
                'ru' => 'Провайдеры',
            ],
            'Usage events' => [
                'ko' => '사용량 이벤트',
                'zh_CN' => '使用事件',
                'ar' => 'أحداث الاستخدام',
                'ru' => 'События использования',
            ],
            'Monitoring' => [
                'ko' => '모니터링',
                'zh_CN' => '监控',
                'ar' => 'المراقبة',
                'ru' => 'Мониторинг',
            ],
            'AI connections' => [
                'ko' => 'AI 연결',
                'zh_CN' => 'AI 连接',
                'ar' => 'اتصالات الذكاء الاصطناعي',
                'ru' => 'AI-подключения',
            ],
            'Activity log' => [
                'ko' => '활동 로그',
                'zh_CN' => '活动日志',
                'ar' => 'سجل النشاط',
                'ru' => 'Журнал действий',
            ],
            'Error log' => [
                'ko' => '오류 로그',
                'zh_CN' => '错误日志',
                'ar' => 'سجل الأخطاء',
                'ru' => 'Журнал ошибок',
            ],
            'Settings' => [
                'ko' => '설정',
                'zh_CN' => '设置',
                'ar' => 'الإعدادات',
                'ru' => 'Настройки',
            ],
            'Platform' => [
                'ko' => '플랫폼',
                'zh_CN' => '平台',
                'ar' => 'المنصة',
                'ru' => 'Платформа',
            ],
            'Notification audiences' => [
                'ko' => '알림 대상',
                'zh_CN' => '通知受众',
                'ar' => 'جماهير الإشعارات',
                'ru' => 'Аудитории уведомлений',
            ],
            'Currencies' => [
                'ko' => '통화',
                'zh_CN' => '货币',
                'ar' => 'العملات',
                'ru' => 'Валюты',
            ],
            'Navigation' => [
                'ko' => '탐색',
                'zh_CN' => '导航',
                'ar' => 'التنقل',
                'ru' => 'Навигация',
            ],
            'Menu list' => [
                'ko' => '메뉴 목록',
                'zh_CN' => '菜单列表',
                'ar' => 'قائمة القوائم',
                'ru' => 'Список меню',
            ],
            'Trashed menus' => [
                'ko' => '삭제된 메뉴',
                'zh_CN' => '已删除菜单',
                'ar' => 'القوائم المحذوفة',
                'ru' => 'Удалённые меню',
            ],
        ];

        $localized = $additional[$english] ?? [
            'ko' => $english,
            'zh_CN' => $english,
            'ar' => $english,
            'ru' => $english,
        ];

        return [
            'en' => $english,
            'id' => $indonesian,
            'ja' => $japanese,
            ...$localized,
        ];
    }
}
