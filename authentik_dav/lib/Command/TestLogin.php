<?php

declare(strict_types=1);

namespace OCA\AuthentikDav\Command;

use OCA\AuthentikDav\Backend;
use OCA\AuthentikDav\Service\AuthentikClient;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * occ authentik_dav:test <user>
 *
 * Runs the same checks as a real WebDAV login (without the cache) and says why it would fail.
 * The password is asked without echo, or read from the AUTHENTIK_DAV_PASSWORD environment variable
 * with --password-from-env. It is never printed or logged.
 */
class TestLogin extends Command {
	public function __construct(
		private Backend $backend,
		private AuthentikClient $authentik,
		private IUserManager $userManager,
		private IRegistry $twoFactor,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('authentik_dav:test')
			->setDescription('Check whether a user could log in to WebDAV with an Authentik password, and why not')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud account name (the Authentik username)')
			->addOption('password-from-env', null, InputOption::VALUE_NONE, 'Read the password from the AUTHENTIK_DAV_PASSWORD environment variable');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');

		$probe = $this->authentik->probe();
		$output->writeln(($probe['ok'] ? '<info>✓</info>' : '<error>✗</error>') . ' Connection to Authentik: ' . $probe['code'] . ' (' . $probe['detail'] . ')');
		if (!$probe['ok']) {
			return 1;
		}

		if ($input->getOption('password-from-env')) {
			$password = (string)getenv('AUTHENTIK_DAV_PASSWORD');
			if ($password === '') {
				$output->writeln('<error>AUTHENTIK_DAV_PASSWORD is empty</error>');
				return 2;
			}
		} else {
			$question = (new Question('Authentik password for ' . $uid . ': '))->setHidden(true)->setHiddenFallback(false);
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');
			$password = (string)$helper->ask($input, $output, $question);
		}

		$reason = $this->backend->diagnose($uid, $password);
		if ($reason !== null) {
			$output->writeln('<error>✗</error> Refused: ' . $reason);
			return 1;
		}
		$output->writeln('<info>✓</info> The Authentik password is accepted for ' . $uid . '.');

		// Nextcloud itself refuses password logins from clients when the account has two-factor authentication.
		$user = $this->userManager->get($uid);
		if ($user !== null && in_array(true, $this->twoFactor->getProviderStates($user), true)) {
			$output->writeln('<comment>!</comment> But this account has two-factor authentication in Nextcloud: Nextcloud refuses every password login from clients, so it needs an app password.');
			return 1;
		}
		return 0;
	}
}
