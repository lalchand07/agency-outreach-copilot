<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Auth;
use App\Http\Request;
use App\Http\Response;

final class AuthController extends Controller
{
    public function form(Request $request, Auth $auth): Response
    {
        return new Response($this->view->render('login', ['configured' => $auth->isConfigured(), 'error' => ''], 'layout_bare'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function login(Request $request, Auth $auth): Response
    {
        if ($auth->attempt($request->input('username'), (string) ($request->post['password'] ?? ''), $request->ip)) {
            return Response::redirect($this->url('/'));
        }
        $error = $auth->isThrottled($request->ip) ? 'Too many failed attempts. Try again in 15 minutes.' : 'Wrong username or password.';
        if (!$auth->isConfigured()) {
            $error = 'Login is not set up yet: see the instructions below.';
        }

        return new Response($this->view->render('login', ['configured' => $auth->isConfigured(), 'error' => $error], 'layout_bare'), 401, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function logout(Request $request, Auth $auth): Response
    {
        $auth->logout();

        return Response::redirect($this->url('/login'));
    }
}
