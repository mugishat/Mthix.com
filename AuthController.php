<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Services\Notifier;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            redirect('/account');
        }
        $this->page('auth/login', ['title' => 'Sign in', 'next' => get('next')]);
    }

    public function login(): void
    {
        $result = Auth::attempt(post('email'), (string)($_POST['password'] ?? ''));
        if ($result !== true) {
            keep_old($_POST);
            flash('error', $result);
            redirect('/login' . (post('next') !== '' ? '?next=' . urlencode(post('next')) : ''));
        }
        clear_old();
        redirect($this->safeNext(post('next')) ?? $this->homeFor());
    }

    public function registerForm(): void
    {
        if (Auth::check()) {
            redirect('/account');
        }
        $this->page('auth/register', ['title' => 'Create your account', 'preRole' => get('role')]);
    }

    public function register(): void
    {
        $name = post('name');
        $email = strtolower(post('email'));
        $phone = preg_replace('/\s+/', '', post('phone')) ?? '';
        $pw = (string)($_POST['password'] ?? '');
        $roles = array_values(array_intersect((array)($_POST['roles'] ?? []), ['seller', 'reseller', 'affiliate', 'delivery']));
        $storeName = post('store_name');

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors[] = 'Enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } elseif (Db::val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with this email already exists. Try signing in.';
        }
        if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            $errors[] = 'Enter a valid phone number, e.g. 0788123456.';
        }
        if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
            $errors[] = 'Password must be at least 8 characters with letters and numbers.';
        }
        if ($pw !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        }
        if (in_array('seller', $roles, true) && mb_strlen($storeName) < 3) {
            $errors[] = 'Give your store a name (at least 3 characters).';
        }
        if ($errors) {
            keep_old($_POST);
            flash('error', implode(' ', $errors));
            redirect('/register');
        }

        $id = Db::tx(function () use ($name, $email, $phone, $pw, $roles, $storeName): int {
            $id = Db::insert(
                'INSERT INTO users (name, email, phone, password_hash, ref_code) VALUES (?,?,?,?,?)',
                [$name, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))]
            );
            foreach (array_unique(array_merge(['buyer'], $roles)) as $r) {
                Db::run('INSERT INTO user_roles (user_id, role) VALUES (?,?)', [$id, $r]);
            }
            if (in_array('seller', $roles, true)) {
                $slug = slugify($storeName);
                while (Db::val('SELECT COUNT(*) FROM stores WHERE slug = ?', [$slug])) {
                    $slug .= '-' . random_int(10, 99);
                }
                Db::run('INSERT INTO stores (user_id, name, slug) VALUES (?,?,?)', [$id, $storeName, $slug]);
            }
            Audit::log($id, 'user.register', 'user', $id, ['roles' => $roles]);
            Notifier::send($id, 'account.welcome', 'Welcome to MTHIX', $roles ? 'Complete identity verification (KYC) to start withdrawing earnings.' : 'Start shopping.', $roles ? '/kyc' : '/');
            return $id;
        });

        clear_old();
        Auth::login($id);
        flash('success', 'Welcome to MTHIX, ' . explode(' ', $name)[0] . '!');
        redirect($roles ? $this->homeFor() : '/');
    }

    public function logout(): void
    {
        Auth::logout();
        session_start();
        flash('success', 'You have been signed out.');
        redirect('/');
    }

    /** Only same-site relative paths are accepted (prevents open redirects). */
    private function safeNext(string $next): ?string
    {
        return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, '\\')) ? $next : null;
    }

    private function homeFor(): string
    {
        foreach (['admin' => '/admin', 'finance_admin' => '/admin', 'support_admin' => '/admin', 'seller' => '/seller', 'delivery' => '/delivery', 'reseller' => '/reseller', 'affiliate' => '/affiliate'] as $role => $href) {
            if (Auth::hasRole($role)) {
                return $href;
            }
        }
        return '/account';
    }
}
