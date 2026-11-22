<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Auth;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Middleware\CsrfMiddleware;
use CeylonCues\Services\Auth\AuthService;
use Exception;

/**
 * Authentication Controller
 */
final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $result = $this->auth->login($data['email'], $data['password']);
            Response::json($result, 'Login successful');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 401);
        }
    }

    /**
     * POST /api/v1/auth/register (Customer Only)
     */
    public function register(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|max:255',
            'phone'      => 'required|string|max:20',
            'password'   => 'required|string|min:8|confirmed',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $this->auth->register($data);
            Response::created($user, 'Registration successful. Welcome to Ceylon Cues!');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): void
    {
        $token = $request->getBearerToken();
        if ($token) {
            $this->auth->logout($token);
        }
        Response::json(null, 'Logged out successfully');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }
        Response::json($user, 'Profile retrieved');
    }

    /**
     * PUT /api/v1/auth/profile
     */
    public function updateProfile(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'first_name' => 'string|max:100',
            'last_name'  => 'string|max:100',
            'phone'      => 'string|max:20',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $updated = $this->auth->updateProfile((int)$user['id'], $data);
        Response::json($updated, 'Profile updated successfully');
    }

    /**
     * POST /api/v1/auth/change-password
     */
    public function changePassword(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $this->auth->changePassword((int)$user['id'], $data['current_password'], $data['new_password']);
            Response::json(null, 'Password updated successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/auth/forgot-password
     */
    public function forgotPassword(Request $request): void
    {
        $data = $request->getBody();
        if (empty($data['email'])) {
            Response::error('Email is required', 400);
        }

        $this->auth->requestPasswordReset($data['email']);
        // Always return generic success to prevent email enumeration
        Response::json(null, 'If your email is registered, password reset instructions will be sent.');
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $this->auth->resetPassword($data['token'], $data['password']);
            Response::json(null, 'Password has been reset successfully. You may now log in.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/auth/csrf-token
     */
    public function csrfToken(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user ? (int)$user['id'] : null;
        $token = CsrfMiddleware::generateToken($userId);
        Response::json(['csrf_token' => $token], 'CSRF token generated');
    }
}
