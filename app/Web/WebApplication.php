<?php

declare(strict_types=1);

namespace HalalPulse\Web;

use HalalPulse\Auth\AuthenticationService;
use HalalPulse\Auth\LoginRateLimiter;
use HalalPulse\Auth\PasswordHasher;
use HalalPulse\Auth\SessionManager;
use HalalPulse\Auth\UserRepository;
use HalalPulse\Config;
use HalalPulse\Dashboard\DashboardRepository;
use HalalPulse\Database;
use HalalPulse\Documents\DocumentReadRepository;
use HalalPulse\Government\GovernmentRepository;
use HalalPulse\Alerts\AlertRepository;
use HalalPulse\Alerts\AlertRecipientCrypto;
use HalalPulse\Alerts\AlertRecipientRepository;
use HalalPulse\Support\JsonLogger;
use HalalPulse\Sharia\ShariaInputCandidateRepository;
use HalalPulse\Sharia\ShariaRepository;
use HalalPulse\Multibagger\MultibaggerRepository;
use PDO;
use RuntimeException;

final readonly class WebApplication
{
    public function __construct(
        public Config $config,
        public PDO $pdo,
        public JsonLogger $logger,
        public UserRepository $users,
        public SessionManager $session,
        public AuthenticationService $authentication,
        public DashboardRepository $dashboard,
        public DocumentReadRepository $documents,
        public ShariaRepository $sharia,
        public ShariaInputCandidateRepository $shariaCandidates,
        public MultibaggerRepository $multibagger,
        public GovernmentRepository $government,
        public AlertRepository $alerts,
        public AlertRecipientRepository $alertRecipients,
    ) {
    }

    public static function boot(Config $config): self
    {
        $appKey = $config->requireString('security.app_key');
        if (strlen($appKey) < 32) {
            throw new RuntimeException('security.app_key must contain at least 32 characters.');
        }

        $pdo = Database::connect($config);
        $logger = new JsonLogger($config->requireString('app.log_path'));
        $users = new UserRepository($pdo);
        $session = new SessionManager(
            name: (string) $config->get('security.session_name', 'halalpulse_session'),
            idleSeconds: (int) $config->get('security.session_idle_seconds', 1800),
            absoluteSeconds: (int) $config->get('security.session_absolute_seconds', 43200),
            secureCookie: (bool) $config->get('app.force_https', true),
        );
        $session->start();
        $hasher = new PasswordHasher();

        return new self(
            config: $config,
            pdo: $pdo,
            logger: $logger,
            users: $users,
            session: $session,
            authentication: new AuthenticationService(
                users: $users,
                rateLimiter: new LoginRateLimiter($pdo),
                hasher: $hasher,
                logger: $logger,
                appKey: $appKey,
                maxAttempts: (int) $config->get('security.login_max_attempts', 5),
                windowSeconds: (int) $config->get('security.login_window_seconds', 900),
            ),
            dashboard: new DashboardRepository($pdo),
            documents: new DocumentReadRepository($pdo),
            sharia: new ShariaRepository($pdo),
            shariaCandidates: new ShariaInputCandidateRepository($pdo),
            multibagger: new MultibaggerRepository($pdo),
            government: new GovernmentRepository($pdo),
            alerts: new AlertRepository($pdo),
            alertRecipients: new AlertRecipientRepository($pdo, new AlertRecipientCrypto($appKey)),
        );
    }
}
