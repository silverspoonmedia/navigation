<?php

return [
    // curated at 2026-09-21T17:32:29+07:00 — ja curation wave
    'tree_success' => 'ナビゲーションツリーを読み込みました。',
    // curated at 2026-09-21T17:32:29+07:00 — ja curation wave
    'layouts_success' => 'ナビゲーションレイアウトを読み込みました。',
    // curated at 2026-09-21T17:32:29+07:00 — ja curation wave
    'list_success' => 'メニューを読み込みました。',
    // curated at 2026-09-21T17:32:29+07:00 — ja curation wave
    'show_success' => 'メニューを読み込みました。',
    // curated at 2026-09-21T17:32:29+07:00 — ja curation wave
    'trashed_success' => 'ゴミ箱のメニューを読み込みました。',
    'created' => 'メニューを作成しました。',
    'updated' => 'メニューを更新しました。',
    'deleted' => 'メニューを削除しました。',
    'restored' => 'メニューを復元しました。',
    'not_found' => 'メニューが見つかりません。',
    // curated at 2026-09-21T17:32:29+07:00 — ja curation wave
    'has_children' => 'このメニューには子項目があります。子孫もまとめて削除するには cascade=true を指定してください。',
    'invalid_route' => 'ルートは登録済みのルート名である必要があります。',
    'position' => [
        'reference_required' => 'before/after の配置には reference_id が必要です。',
        'reference_not_in_group' => 'reference_id が同じ兄弟グループ内にありません。',
        'invalid_position' => 'position は before または after である必要があります。',
        'reference_same_menu' => 'reference_id は同じメニューにできません。',
        'reference_wrong_group' => '基準メニューは対象グループと parent_id および type を共有する必要があります。',
    ],
    'parent_restore_failed' => '復元できません: 親メニューが存在しないか、まだ削除されています。先に親を復元してください。',
];
