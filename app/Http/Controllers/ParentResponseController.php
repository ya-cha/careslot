<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParentResponseRequest;
use App\Services\ParentResponseService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ParentResponseController extends Controller
{
    public function __construct(private ParentResponseService $responses) {}

    public function show(string $token): View
    {
        $access = $this->responses->access($token);

        return view('parent.response', ['response' => $access->response, 'allocation' => $access->response->allocation, 'token' => $token]);
    }

    public function update(ParentResponseRequest $request, string $token): RedirectResponse
    {
        $this->responses->save($token, $request->validated());

        return redirect()->route('parent.show', $token)->with('success', 'Ihre Rückmeldung wurde gespeichert. Bis zum Ende der Rückmeldungsphase können Sie sie ändern.');
    }
}
