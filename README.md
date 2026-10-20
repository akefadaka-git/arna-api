<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


## ARNA system-principal evidence storage (I2)

This repository includes candidate storage primitives for versioned system-principal policies, append-only action audits, and tenant/action-scoped idempotency records. These primitives do **not** authorize or execute Loan actions, provision a system principal, activate tenant grants, or enable automated approval, rejection, or disbursement. Automation remains disabled by default.

### Restricted MySQL application-role contract

The application database identity must receive only these table-level privileges for the evidence tables:

| Table | Required privileges |
| --- | --- |
| `system_principal_policy_versions` | `SELECT, INSERT` |
| `system_principal_action_audits` | `SELECT, INSERT` |
| `system_principal_idempotency_records` | `SELECT, INSERT, UPDATE` |

The application identity must have no `DELETE` privilege on these tables and no `UPDATE` privilege on policy-version or action-audit records. Idempotency updates are needed for the controlled `reserved → completed` or `reserved → failed` transition; database triggers enforce transition invariants and reject deletion. MySQL table-level grants cannot restrict `UPDATE` to particular columns, so the trigger remains a required complementary control.

Schema and trigger changes must use a separately controlled migration/administrative identity. The application identity must not be able to create or drop users, grant privileges, alter evidence tables, or modify immutability triggers. Production provisioning must use reviewed grants and an appropriately restricted account host; the wildcard host used by isolated CI setup is not a production-hosting recommendation.

### Verification status

The MySQL acceptance test currently provisions a restricted CI account and checks selected read and mutation behavior. Before the restricted-role gate can be considered complete, the test must explicitly verify permitted app-role inserts, idempotency reads/inserts and a valid terminal transition, idempotency delete denial, effective grants, and distinguish privilege-denial errors from trigger rejections. Production-role provisioning and actual production grants remain unverified until independently inspected.

See `docs/architecture/ARNA-SYSTEM-PRINCIPAL-PROVENANCE-001.md` and `tests/Feature/SystemPrincipalMySqlConcurrencyAndImmutabilityTest.php` for the implementation boundary and acceptance coverage. This documentation update does not indicate that the remaining checks have passed or authorize deployment.
