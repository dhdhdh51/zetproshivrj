<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'layouts.app'): void
    {
        Response::html(View::render($view, $data, $layout));
    }

    protected function json(array $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    protected function redirect(string $to): void
    {
        Response::redirect($to);
    }

    protected function back(string $fallback = '/'): void
    {
        Response::back($fallback);
    }

    protected function success(string $message): void
    {
        Session::flash('success', $message);
    }

    protected function error(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function info(string $message): void
    {
        Session::flash('info', $message);
    }

    /**
     * Validate request data. On failure the user is redirected back with errors
     * (or receives a 422 JSON payload for AJAX requests).
     */
    protected function validate(Request $request, array $rules, array $messages = [], ?string $redirectTo = null): array
    {
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->passes()) {
            return $validator->validated();
        }

        if ($request->isAjax()) {
            Response::json([
                'success' => false,
                'message' => $validator->firstError() ?? 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
            exit;
        }

        Session::flashErrors($validator->errors());
        Session::flashInput($request->all());
        Session::flash('error', $validator->firstError() ?? 'Please check the form and try again.');

        if ($redirectTo !== null) {
            Response::redirect($redirectTo);
        } else {
            Response::back();
        }

        exit;
    }

    protected function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }
}
