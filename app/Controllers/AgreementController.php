<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Agreement;

/**
 * 协议展示：用户协议 / 隐私政策 / 退款政策
 */
final class AgreementController extends Controller
{
    public function show(string $type): void
    {
        if (!in_array($type, Agreement::TYPES, true)) {
            abort(404, t('协议不存在。'));
        }

        $agreement = Agreement::current($type);

        if ($agreement === null) {
            abort(404, t('协议不存在。'));
        }

        $this->view('agreements.show', [
            'pageTitle' => (string) $agreement['title'],
            'agreement' => $agreement,
        ]);
    }
}
