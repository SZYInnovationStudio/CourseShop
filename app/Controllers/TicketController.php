<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Support\Auth;
use App\Support\Captcha;
use App\Support\Csrf;
use App\Support\LoginThrottle;
use App\Support\Permission;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Setting;
use App\Support\TicketNotifier;
use App\Support\TicketStorage;

/**
 * 工单：我的工单、提交、详情、回复、关闭、删除、附件下载
 *
 * 另有公开的「账号申诉」入口（封禁用户无法登录，故不经过 auth 中间件）。
 */
final class TicketController extends Controller
{
    /** 我的工单每页条数 */
    private const PER_PAGE = 10;

    /** 单条回复最大长度 */
    private const MAX_REPLY_LENGTH = 5000;

    /**
     * 我的工单列表
     */
    public function index(): void
    {
        $user = $this->requireUser();

        $filters = [
            'keyword' => Request::string('keyword'),
            'status'  => Request::string('status'),
        ];

        if (!isset(Ticket::STATUS_LABELS[$filters['status']])) {
            $filters['status'] = '';
        }

        $page       = max(1, Request::int('page', 1));
        $total      = Ticket::countForUser((int) $user['id'], $filters);
        $perPage    = self::PER_PAGE;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $tickets    = Ticket::listForUser((int) $user['id'], $filters, $perPage, ($page - 1) * $perPage);

        $this->view('tickets.index', [
            'pageTitle' => '我的工单',
            'tickets'   => $tickets,
            'filters'   => $filters,
            'total'     => $total,
            'page'      => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * 提交工单表单
     */
    public function create(): void
    {
        $this->requireUser();

        if (!Setting::bool('ticket_enabled', true)) {
            $this->fail(url('/tickets'), '当前未开放工单提交，如有紧急问题请通过其他方式联系管理员。');
        }

        $this->view('tickets.create', [
            'pageTitle'      => '提交工单',
            'categories'     => Ticket::CATEGORIES,
            'maxFiles'       => TicketStorage::maxFiles(),
            'maxMb'          => TicketStorage::maxUploadMb(),
            'allowedTypes'   => TicketStorage::allowedExtensions(),
        ]);
    }

    /**
     * 保存新工单
     */
    public function store(): void
    {
        Csrf::check();

        $user = $this->requireUser();

        if (!Setting::bool('ticket_enabled', true)) {
            $this->fail(url('/tickets'), '当前未开放工单提交。');
        }

        $old = [
            'category' => Request::string('category'),
            'title'    => Request::string('title'),
            'content'  => (string) Request::input('content', ''),
        ];

        $validator = $this->validator(Request::all())
            ->required('title', '标题')
            ->min('title', 4, '标题')
            ->max('title', 150, '标题')
            ->required('content', '问题描述')
            ->min('content', 5, '问题描述')
            ->max('content', self::MAX_REPLY_LENGTH, '问题描述');

        if ($validator->fails()) {
            $this->fail(url('/ticket/create'), (string) $validator->firstError(), $old);
        }

        $category = (string) $old['category'];
        if (!isset(Ticket::CATEGORIES[$category])) {
            $category = 'other';
        }

        $ticketId = $this->insertTicket([
            'user_id'  => (int) $user['id'],
            'type'     => Ticket::TYPE_NORMAL,
            'category' => $category,
            'title'    => (string) $old['title'],
            'content'  => trim((string) $old['content']),
        ]);

        $errors = TicketStorage::saveUploaded($ticketId, null, (int) $user['id'], Request::file('attachments'));

        $ticket = Ticket::findById($ticketId);
        if ($ticket !== null) {
            TicketNotifier::notifyAdmins($ticket, '有新工单');
        }

        if ($errors !== []) {
            Session::flash('error', '工单已提交，但部分附件未能上传：' . implode('；', $errors));
        }

        $this->success(url('/ticket/' . $ticketId), '工单提交成功，我们会尽快处理。');
    }

    /**
     * 工单详情
     */
    public function show(string $id): void
    {
        $user  = $this->requireUser();
        $ticket = Ticket::findByIdForUser((int) $id, (int) $user['id']);

        if ($ticket === null) {
            abort(404, '工单不存在或已被删除。');
        }

        $replies = TicketReply::listByTicket((int) $ticket['id']);
        $grouped = TicketAttachment::groupByReply((int) $ticket['id']);

        $this->view('tickets.show', [
            'pageTitle'  => '工单详情',
            'ticket'     => $ticket,
            'replies'    => $replies,
            'attachments' => $grouped,
            'maxFiles'   => TicketStorage::maxFiles(),
            'maxMb'      => TicketStorage::maxUploadMb(),
            'allowedTypes' => TicketStorage::allowedExtensions(),
        ]);
    }

    /**
     * 用户回复工单
     */
    public function reply(string $id): void
    {
        Csrf::check();

        $user   = $this->requireUser();
        $ticket = Ticket::findByIdForUser((int) $id, (int) $user['id']);

        if ($ticket === null) {
            abort(404, '工单不存在或已被删除。');
        }

        if ((string) $ticket['status'] === Ticket::STATUS_CLOSED) {
            $this->fail(url('/ticket/' . $ticket['id']), '该工单已关闭，无法继续回复。');
        }

        $content = trim((string) Request::input('content', ''));

        if ($content === '') {
            $this->fail(url('/ticket/' . $ticket['id']), '回复内容不能为空。');
        }

        if (mb_strlen($content) > self::MAX_REPLY_LENGTH) {
            $this->fail(url('/ticket/' . $ticket['id']), '回复内容不能超过 ' . self::MAX_REPLY_LENGTH . ' 个字符。');
        }

        $replyId = TicketReply::create((int) $ticket['id'], (int) $user['id'], false, $content);
        Ticket::incrementReply((int) $ticket['id']);

        $errors = TicketStorage::saveUploaded((int) $ticket['id'], $replyId, (int) $user['id'], Request::file('attachments'));

        // 用户补充信息后，工单回到「处理中」，等待管理员再次查看
        Ticket::setStatus((int) $ticket['id'], Ticket::STATUS_PROCESSING);

        $updated = Ticket::findById((int) $ticket['id']);
        if ($updated !== null) {
            TicketNotifier::notifyAdmins($updated, '有新的用户回复');
        }

        if ($errors !== []) {
            Session::flash('error', '回复已提交，但部分附件未能上传：' . implode('；', $errors));
        }

        $this->success(url('/ticket/' . $ticket['id']), '回复已提交。');
    }

    /**
     * 用户关闭工单
     */
    public function close(string $id): void
    {
        Csrf::check();

        $user   = $this->requireUser();
        $ticket = Ticket::findByIdForUser((int) $id, (int) $user['id']);

        if ($ticket === null) {
            abort(404, '工单不存在或已被删除。');
        }

        if (!Ticket::closeByUser((int) $ticket['id'], (int) $user['id'])) {
            $this->fail(url('/ticket/' . $ticket['id']), '工单已关闭或状态不允许关闭。');
        }

        $this->success(url('/ticket/' . $ticket['id']), '工单已关闭。');
    }

    /**
     * 用户删除（软删除）工单
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $user   = $this->requireUser();
        $ticket = Ticket::findByIdForUser((int) $id, (int) $user['id']);

        if ($ticket === null) {
            abort(404, '工单不存在或已被删除。');
        }

        if (!Ticket::softDeleteByUser((int) $ticket['id'], (int) $user['id'])) {
            $this->fail(url('/ticket/' . $ticket['id']), '删除失败，请稍后重试。');
        }

        $this->success(url('/tickets'), '工单已删除。');
    }

    /**
     * 下载工单附件（需登录且为该工单所有者或管理员）
     */
    public function downloadAttachment(string $id): void
    {
        $user = $this->requireUser();

        $attachment = TicketAttachment::findById((int) $id);
        if ($attachment === null) {
            abort(404, '附件不存在。');
        }

        $ticket = Ticket::findById((int) $attachment['ticket_id']);
        if ($ticket === null) {
            abort(404, '附件不存在。');
        }

        $isOwner = (int) $ticket['user_id'] === (int) $user['id'];

        // 非工单所有者必须是拥有工单查看权限的后台人员（CS-08）：
        // 仅有后台身份但未获 ticket.view 的角色，不得下载他人上传的申诉材料。
        if (!$isOwner && !Permission::can('ticket.view')) {
            abort(403, '无权访问该附件。');
        }

        $real = TicketStorage::resolve((string) $attachment['file_path']);
        if ($real === null) {
            abort(404, '附件文件不存在或已被清理。');
        }

        $this->outputFile($real, (string) $attachment['file_name'], (string) $attachment['mime_type']);
    }

    /**
     * 账号申诉表单（公开，无需登录）
     */
    public function showAppeal(): void
    {
        $this->view('tickets.appeal', [
            'pageTitle'       => '账号申诉',
            'captchaRequired' => Captcha::enabled(),
        ]);
    }

    /**
     * 提交账号申诉
     */
    public function submitAppeal(): void
    {
        Csrf::check();

        $username = Request::string('username');
        $password = Request::raw('password');
        $content  = trim((string) Request::input('content', ''));
        $old      = ['username' => $username, 'content' => $content];

        $validator = $this->validator(Request::all())
            ->required('username', '用户名')
            ->required('password', '密码')
            ->required('content', '申诉说明')
            ->min('content', 10, '申诉说明')
            ->max('content', self::MAX_REPLY_LENGTH, '申诉说明');

        if ($validator->fails()) {
            $this->fail(url('/ticket/appeal'), (string) $validator->firstError(), $old);
        }

        // 失败过多则临时锁定，避免借申诉接口无限爆破账号密码
        $remaining = LoginThrottle::lockRemaining($username);
        if ($remaining > 0) {
            $this->fail(url('/ticket/appeal'), sprintf('失败次数过多，请 %d 分钟后再试。', (int) ceil($remaining / 60)), $old);
        }

        if (Captcha::enabled() && !Captcha::verify('appeal', Request::string('captcha'))) {
            $this->fail(url('/ticket/appeal'), '图形验证码不正确或已过期。', $old);
        }

        $user = User::findByUsername($username);

        // 统一提示，避免暴露账号是否存在；同时记录失败以驱动登录风控计数
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            User::logLogin($user === null ? null : (int) $user['id'], $username, 'fail', '账号申诉：账号或密码错误');
            $this->fail(url('/ticket/appeal'), '用户名或密码不正确，请确认后再提交申诉。', $old);
        }

        if (!Auth::isBanned($user)) {
            $this->fail(url('/ticket/appeal'), '该账号当前未被封禁，无需申诉。如忘记密码请通过登录页找回。', $old);
        }

        if (Ticket::hasOpenAppeal((int) $user['id'])) {
            $this->fail(url('/ticket/appeal'), '你已有一条正在处理的申诉，请耐心等待处理结果。', $old);
        }

        $ticketId = $this->insertTicket([
            'user_id'  => (int) $user['id'],
            'type'     => Ticket::TYPE_APPEAL,
            'category' => 'account',
            'title'    => '账号申诉：' . $username,
            'content'  => $content,
            'priority' => 2,
        ]);

        $ticket = Ticket::findById($ticketId);
        if ($ticket !== null) {
            TicketNotifier::notifyAdmins($ticket, '有新的账号申诉');
        }

        $this->success(url('/login'), '申诉已提交，管理员会尽快核实处理，请留意账号绑定的邮箱通知。');
    }

    // ============================================================
    // 内部辅助
    // ============================================================

    /**
     * @return array<string, mixed>
     */
    private function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            Response::redirect(url('/login'));
        }

        return $user;
    }

    /**
     * 写入工单，必要时重试以避免工单号冲突
     *
     * @param array<string, mixed> $data
     */
    private function insertTicket(array $data): int
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $data['ticket_no'] = Ticket::generateNo();

            if ($this->ticketNoExists((string) $data['ticket_no'])) {
                continue;
            }

            return Ticket::create($data);
        }

        abort(500, '生成工单号失败，请稍后重试。');
    }

    private function ticketNoExists(string $ticketNo): bool
    {
        return \App\Support\Database::scalar(
            'SELECT COUNT(*) FROM `tickets` WHERE `ticket_no` = ?',
            [$ticketNo]
        ) > 0;
    }

    /**
     * 以附件形式输出文件
     */
    private function outputFile(string $file, string $fileName, string $mimeType): never
    {
        $size = (int) filesize($file);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="' . $this->safeFileName($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        readfile($file);
        exit;
    }

    /**
     * 生成安全的下载文件名（避免响应头注入）
     */
    private function safeFileName(string $fileName): string
    {
        $fileName = str_replace(['"', '\\', "\r", "\n"], '', $fileName);

        return $fileName !== '' ? $fileName : 'attachment';
    }
}
