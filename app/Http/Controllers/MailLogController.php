<?php

namespace App\Http\Controllers;

use App\Models\MailLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

class MailLogController extends Controller
{
    public function index(): View
    {
        return view('admin.mail-logs', [
            'logs' => MailLog::select(['id', 'from', 'to', 'subject', 'sent_at'])->orderByDesc('id')->paginate(30),
        ]);
    }

    public function show(MailLog $mailLog): View
    {
        return view('admin.mail-log', ['mailLog' => $mailLog]);
    }

    public function content(MailLog $mailLog): Response
    {
        return response($mailLog->html)->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
        ]);
    }
}
