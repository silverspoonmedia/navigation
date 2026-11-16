<?php

return [
    'tree_success' => 'تم جلب شجرة التنقل بنجاح.',
    'layouts_success' => 'تم جلب تخطيطات التنقل بنجاح.',
    'list_success' => 'تم جلب القوائم بنجاح.',
    'show_success' => 'تم جلب القائمة بنجاح.',
    'trashed_success' => 'تم جلب القوائم المحذوفة بنجاح.',
    'created' => 'تم إنشاء القائمة بنجاح.',
    'updated' => 'تم تحديث القائمة بنجاح.',
    'deleted' => 'تم حذف القائمة بنجاح.',
    'restored' => 'تمت استعادة القائمة بنجاح.',
    'not_found' => 'لم يتم العثور على القائمة.',
    'has_children' => 'تحتوي هذه القائمة على عناصر فرعية. مرّر cascade=true لحذفها مع عناصرها التابعة.',
    // curated at 2026-09-22T20:32:26+07:00 — ar curation wave
    'invalid_route' => 'يجب أن يكون حقل المسار اسم مسار مسجّل.',
    'position' => [
        // curated at 2026-09-22T20:32:26+07:00 — ar curation wave
        'reference_required' => 'الحقل reference_id مطلوب لتحديد الموضع (قبل أو بعد).',
        'reference_not_in_group' => 'لا ينتمي reference_id إلى مجموعة الأشقاء نفسها.',
        'invalid_position' => 'يجب أن يكون الموضع قبل أو بعد.',
        'reference_same_menu' => 'لا يمكن أن يطابق reference_id القائمة نفسها.',
        'reference_wrong_group' => 'يجب أن تشترك القائمة المرجعية مع المجموعة الهدف في parent_id والنوع.',
    ],
    // curated at 2026-09-22T20:32:26+07:00 — ar curation wave
    'parent_restore_failed' => 'لا يمكن الاستعادة: القائمة الرئيسية مفقودة أو لا تزال محذوفة. استعد القائمة الرئيسية أولًا.',
];
