<?php

namespace App\Domain\Navigation\Database\Seeders;

use App\Domain\Identity\Models\Role;
use App\Domain\Navigation\Enums\MenuAudience;
use App\Domain\Navigation\Models\Menu;
use App\Domain\Navigation\Repositories\Contracts\MenuRepositoryInterface;
use App\Domain\Navigation\Support\MenuLayout;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rebuilds the canonical dynamic navigation tree.
 *
 * This seeder is intentionally authoritative: every run replaces all active and
 * trashed menu rows so existing installations converge on the same UX tree.
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

        DB::transaction(function (): void {
            Menu::withTrashed()->forceDelete();

            $this->seedTree($this->sidebarTree(), MenuLayout::SIDEBAR);
            $this->seedTree($this->navbarTree(), MenuLayout::NAVBAR);
        });

        app(MenuRepositoryInterface::class)->flushNavigationCache();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function seedTree(array $nodes, string $layout, ?string $parentId = null): void
    {
        foreach ($nodes as $index => $node) {
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
                .'. Seed RoleSeeder and ArchivdRoleSeeder before MenuSeeder.'
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
                'name' => $this->translations('Workspace', 'Ruang Kerja', 'ワークスペース'),
                'type' => 'h',
                'children' => [
                    [
                        'name' => $this->translations('Dashboard', 'Beranda', 'ダッシュボード'),
                        'icon' => 'bi bi-speedometer2',
                        'route' => 'dashboard',
                        'type' => 'p',
                    ],
                    [
                        'name' => $this->translations('Notifications', 'Notifikasi', '通知'),
                        'icon' => 'bi bi-bell',
                        'route' => 'notifications.index',
                        'type' => 'p',
                    ],
                    [
                        'name' => $this->translations('Services', 'Layanan', 'サービス'),
                        'icon' => 'bi bi-box-seam',
                        'route' => 'services.index',
                        'type' => 'p',
                    ],
                ],
            ],
            [
                'name' => $this->translations('Products', 'Produk', 'プロダクト'),
                'type' => 'h',
                'children' => [
                    [
                        'name' => $this->translations('Archivd', 'Archivd', 'Archivd'),
                        'icon' => 'bi bi-cloud',
                        'route' => 'archivd.index',
                        'type' => 'p',
                        'children' => [
                            [
                                'name' => $this->translations('My Files', 'File Saya', 'マイファイル'),
                                'icon' => 'bi bi-folder2-open',
                                'route' => 'archivd.index',
                                'type' => 'c',
                            ],
                            [
                                'name' => $this->translations('Trash', 'Sampah', 'ゴミ箱'),
                                'icon' => 'bi bi-trash3',
                                'route' => 'archivd.trash',
                                'type' => 'c',
                            ],
                            [
                                'name' => $this->translations('DMCA Blocklist', 'Daftar Blokir DMCA', 'DMCA ブロックリスト'),
                                'icon' => 'bi bi-shield-x',
                                'route' => 'archivd.blocklist.index',
                                'type' => 'c',
                                'roles' => [...$managementRoles, 'Archivd Admin'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => $this->translations('Administration', 'Administrasi', '管理'),
                'type' => 'h',
                'children' => [
                    [
                        'name' => $this->translations('Access control', 'Kontrol akses', 'アクセス管理'),
                        'icon' => 'bi bi-people',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'name' => $this->translations('User management', 'Manajemen Pengguna', 'ユーザー管理'),
                                'icon' => 'bi bi-people',
                                'route' => 'users.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Product activations', 'Aktivasi Produk', 'プロダクト有効化'),
                                'icon' => 'bi bi-toggle-on',
                                'route' => 'product-activations.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Product domain access', 'Akses Domain Produk', 'プロダクトドメインアクセス'),
                                'icon' => 'bi bi-shield-lock',
                                'route' => 'domain-access.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'name' => $this->translations('Payments', 'Pembayaran', '決済'),
                        'icon' => 'bi bi-credit-card',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'name' => $this->translations('Payment income', 'Pendapatan pembayaran', '決済収入'),
                                'icon' => 'bi bi-graph-up-arrow',
                                'route' => 'payments.income.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Invoices', 'Invoice', '請求書'),
                                'icon' => 'bi bi-receipt',
                                'route' => 'invoices.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Subscriptions', 'Langganan', 'サブスクリプション'),
                                'icon' => 'bi bi-arrow-repeat',
                                'route' => 'subscriptions.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Payment channels', 'Channel pembayaran', '決済チャネル'),
                                'icon' => 'bi bi-credit-card-2-front',
                                'route' => 'payment-channels.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Payment gateways', 'Payment gateway', '決済ゲートウェイ'),
                                'icon' => 'bi bi-diagram-3',
                                'route' => 'payment-gateways.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                    [
                        'name' => $this->translations('Inference', 'Inferensi', '推論'),
                        'icon' => 'bi bi-cpu',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'name' => $this->translations('Model catalog', 'Katalog model', 'モデルカタログ'),
                                'icon' => 'bi bi-boxes',
                                'route' => 'inference.model-cards.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Providers', 'Provider', 'プロバイダー'),
                                'icon' => 'bi bi-hdd-network',
                                'route' => 'inference.providers.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
                                'name' => $this->translations('Usage events', 'Event penggunaan', '利用イベント'),
                                'icon' => 'bi bi-graph-up',
                                'route' => 'inference.usage-events.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                        ],
                    ],
                    [
                        'name' => $this->translations('AI connections', 'Koneksi AI', 'AI 接続'),
                        'icon' => 'bi bi-plug',
                        'route' => 'ai.connections.index',
                        'type' => 'p',
                        'roles' => $managementRoles,
                    ],
                    [
                        'name' => $this->translations('Monitoring', 'Pemantauan', '監視'),
                        'icon' => 'bi bi-activity',
                        'type' => 'p',
                        'roles' => $adminRoles,
                        'children' => [
                            [
                                'name' => $this->translations('Activity log', 'Log Aktivitas', 'アクティビティログ'),
                                'icon' => 'bi bi-journal-text',
                                'route' => 'activity-logs.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'name' => $this->translations('Error log', 'Log Error', 'エラーログ'),
                                'icon' => 'bi bi-bug',
                                'route' => 'error-logs.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'name' => $this->translations('Settings', 'Pengaturan', '設定'),
                        'icon' => 'bi bi-gear',
                        'type' => 'p',
                        'roles' => $adminRoles,
                        'children' => [
                            [
                                'name' => $this->translations('Platform', 'Platform', 'プラットフォーム'),
                                'icon' => 'bi bi-sliders',
                                'route' => 'settings.platform',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'name' => $this->translations('Archivd', 'Archivd', 'Archivd'),
                                'icon' => 'bi bi-cloud',
                                'route' => 'settings.archivd',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'name' => $this->translations('Microstock', 'Microstock', 'Microstock'),
                                'icon' => 'bi bi-images',
                                'route' => 'settings.microstock',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'name' => $this->translations('Notification audiences', 'Audiens notifikasi', '通知オーディエンス'),
                                'icon' => 'bi bi-bell',
                                'route' => 'notification-audiences.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                            [
                                'name' => $this->translations('Currencies', 'Mata uang', '通貨'),
                                'icon' => 'bi bi-currency-exchange',
                                'route' => 'currencies.index',
                                'type' => 'c',
                                'roles' => $adminRoles,
                            ],
                        ],
                    ],
                    [
                        'name' => $this->translations('Navigation', 'Navigasi', 'ナビゲーション'),
                        'icon' => 'bi bi-list-nested',
                        'type' => 'p',
                        'roles' => $managementRoles,
                        'children' => [
                            [
                                'name' => $this->translations('Menu list', 'Daftar Menu', 'メニュー一覧'),
                                'icon' => 'bi bi-list-ul',
                                'route' => 'menus.index',
                                'type' => 'c',
                                'roles' => $managementRoles,
                            ],
                            [
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
            'Archivd' => [
                'ko' => 'Archivd',
                'zh_CN' => 'Archivd',
                'ar' => 'Archivd',
                'ru' => 'Archivd',
            ],
            'My Files' => [
                'ko' => '내 파일',
                'zh_CN' => '我的文件',
                'ar' => 'ملفاتي',
                'ru' => 'Мои файлы',
            ],
            'Trash' => [
                'ko' => '휴지통',
                'zh_CN' => '回收站',
                'ar' => 'المهملات',
                'ru' => 'Корзина',
            ],
            'DMCA Blocklist' => [
                'ko' => 'DMCA 차단 목록',
                'zh_CN' => 'DMCA 黑名单',
                'ar' => 'قائمة حظر DMCA',
                'ru' => 'Список блокировки DMCA',
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
            'Microstock' => [
                'ko' => '마이크로스톡',
                'zh_CN' => 'Microstock',
                'ar' => 'Microstock',
                'ru' => 'Microstock',
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
