<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Log;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\TicketNotifier;

/**
 * 后台工单管理
 *
 * 提供工单列表与筛选、工单详情（会话记录）、管理员回复、状态与优先级调整、
 * 软删除，以及申诉工单的「解封」联动。
 */
final class TicketController extends AdminController
{
    /** 每页条数 */
    private const PER_PAGE = 20;

    /** 管理员回复最大长度 */
    private const MAX_REPLY_LENGTH = 5000;

    /**
     * 工单列表
     */
    public function index(): void
    {
        $filters = [
            'keyword'   => Request::string('keyword'),
            'status'    => Request::string('status'),
            'type'      => Request::string('type'),
            'priority'  => Request::string('priority'),
            'date_from' => Request::string('date_from'),
            'date_to'   => Request::string('date_to'),
        ];

        // 归一化，避免非法值进入 SQL 条件拼装
        if (!isset(Ticket::STATUS_LABELS[$filters['status']])) {
            $filters['status'] = '';
        }
        if (!isset(Ticket::TYPE_LABELS[$filters['type']])) {
            $filters['type'] = '';
        }
        if ($filters['priority'] !== '' && !isset(Ticket::PRIORITY_LABELS[(int) $filters['priority']])) {
            $filters['priority'] = '';
        }

        $total      = Ticket::adminCount($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min(max(1, Request::int('page', 1)), $totalPages);

        $this->view('admin.tickets.index', [
            'pageTitle'  => '工单管理',
            'filters'    => $filters,
            'tickets'    => Ticket::adminList($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'statuses'   => Ticket::STATUS_LABELS,
            'types'      => Ticket::TYPE_LABELS,
            'priorities' => Ticket::PRIORITY_LABELS,
        ]);
    }

    /**
     * 工单详情
     */
    public function show(string $id): void
    {
        $ticket = Ticket::adminFind((int) $id);

        if ($ticket === null) {
            $this->fail(url('/admin/tickets'), '工单不存在或已被删除。');
        }

        $userId = (int) $ticket['user_id'];
        $user   = $ticket['user_status'] !== null ? [
            'status'        => (int) $ticket['user_status'],
            'ban_type'      => (string) ($ticket['user_ban_type'] ?? 'none'),
            'banned_until'  => $ticket['user_banned_until'],
            'ban_reason'    => $ticket['user_ban_reason'],
        ] : null;

        $this->view('admin.tickets.show', [
            'pageTitle'   => '工单详情',
            'ticket'      => $ticket,
            'replies'     => TicketReply::listByTicket((int) $ticket['id']),
            'attachments' => TicketAttachment::groupByReply((int) $ticket['id']),
            'statuses'    => Ticket::STATUS_LABELS,
            'priorities'  => Ticket::PRIORITY_LABELS,
            'isAppeal'    => (string) $ticket['type'] === Ticket::TYPE_APPEAL,
            'userBanned'  => $user !== null && Auth::isBanned($user),
            'userId'      => $userId,
        ]);
    }

    /**
     * 管理员回复工单
     */
    public function reply(string $id): void
    {
        Csrf::check();

        $ticketId = (int) $id;
        $ticket   = $this->requireActiveTicket($ticketId);

        $content = trim((string) Request::input('content', ''));

        if ($content === '') {
            $this->fail(url('/admin/tickets/' . $ticketId), '回复内容不能为空。');
        }

        if (mb_strlen($content) > self::MAX_REPLY_LENGTH) {
            $this->fail(url('/admin/tickets/' . $ticketId), '回复内容不能超过 ' . self::MAX_REPLY_LENGTH . ' 个字符。');
        }

        TicketReply::create($ticketId, (int) (Auth::id() ?? 0), true, $content);
        Ticket::incrementReply($ticketId);
        Ticket::setStatus($ticketId, Ticket::STATUS_REPLIED);

        $updated = Ticket::findById($ticketId);
        if ($updated !== null) {
            TicketNotifier::notifyOwnerReplied($updated, $content);
        }

        Log::recordOperation('ticket.reply', 'ticket', $ticketId, ['ticket_no' => (string) $ticket['ticket_no']]);

        $this->success(url('/admin/tickets/' . $ticketId), '回复已发送。');
    }

    /**
     * 调整工单状态
     */
    public function setStatus(string $id): void
    {
        Csrf::check();

        $ticketId = (int) $id;
        $ticket   = $this->requireActiveTicket($ticketId);

        $status = Request::string('status');
        if (!isset(Ticket::STATUS_LABELS[$status])) {
            $this->fail(url('/admin/tickets/' . $ticketId), '无效的状态。');
        }

        Ticket::setStatus($ticketId, $status, (int) (Auth::id() ?? 0));

        $updated = Ticket::findById($ticketId);
        if ($updated !== null) {
            TicketNotifier::notifyOwnerStatusChanged($updated);
        }

        Log::recordOperation('ticket.status', 'ticket', $ticketId, [
            'ticket_no' => (string) $ticket['ticket_no'],
            'status'    => $status,
        ]);

        $this->success(url('/admin/tickets/' . $ticketId), '工单状态已更新为「' . Ticket::statusLabel($status) . '」。');
    }

    /**
     * 调整优先级
     */
    public function setPriority(string $id): void
    {
        Csrf::check();

        $ticketId = (int) $id;
        $ticket   = $this->requireActiveTicket($ticketId);

        $priority = Request::int('priority', -1);
        if (!Ticket::setPriority($ticketId, $priority)) {
            $this->fail(url('/admin/tickets/' . $ticketId), '无效的优先级。');
        }

        Log::recordOperation('ticket.priority', 'ticket', $ticketId, [
            'ticket_no' => (string) $ticket['ticket_no'],
            'priority'  => $priority,
        ]);

        $this->success(url('/admin/tickets/' . $ticketId), '优先级已更新为「' . Ticket::priorityLabel($priority) . '」。');
    }

    /**
     * 申诉工单：核实后解除账号封禁
     */
    public function unban(string $id): void
    {
        Csrf::check();

        $ticketId = (int) $id;
        $ticket   = $this->requireActiveTicket($ticketId);

        if ((string) $ticket['type'] !== Ticket::TYPE_APPEAL) {
            $this->fail(url('/admin/tickets/' . $ticketId), '仅申诉工单支持解封操作。');
        }

        $userId = (int) $ticket['user_id'];
        User::unban($userId);

        TicketReply::create($ticketId, (int) (Auth::id() ?? 0), true, '已核实申诉，账号封禁已解除，请重新登录。');
        Ticket::incrementReply($ticketId);
        Ticket::setStatus($ticketId, Ticket::STATUS_REPLIED);

        $updated = Ticket::findById($ticketId);
        if ($updated !== null) {
            TicketNotifier::notifyOwnerStatusChanged($updated);
        }

        Log::recordOperation('ticket.unban', 'ticket', $ticketId, [
            'ticket_no' => (string) $ticket['ticket_no'],
            'user_id'   => $userId,
        ]);

        $this->success(url('/admin/tickets/' . $ticketId), '账号已解封。');
    }

    /**
     * 软删除工单（保留数据，仅从列表隐藏）
     */
    public function destroy(string $id): void
    {
        Csrf::check();

        $ticketId = (int) $id;
        $ticket   = $this->requireActiveTicket($ticketId);

        if (!Ticket::softDelete($ticketId)) {
            $this->fail(url('/admin/tickets'), '工单不存在或已被删除。');
        }

        Log::recordOperation('ticket.delete', 'ticket', $ticketId, ['ticket_no' => (string) $ticket['ticket_no']]);

        $this->success(url('/admin/tickets'), '工单已删除（历史数据保留）。');
    }

    /**
     * 读取未删除的工单，不存在则中断
     *
     * @return array<string, mixed>
     */
    private function requireActiveTicket(int $ticketId): array
    {
        $ticket = Ticket::findById($ticketId);

        if ($ticket === null) {
            $this->fail(url('/admin/tickets'), '工单不存在或已被删除。');
        }

        return $ticket;
    }
}
