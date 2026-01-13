<?php

declare(strict_types=1);

namespace CrisperCode\Console\Command;

use CrisperCode\Entity\User;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\UserRoleManager;
use CrisperCode\Enum\Roles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to assign a role to a user.
 *
 * @package CrisperCode\Console\Command
 */
#[AsCommand(
    name: 'user:assign-role',
    description: 'Assign a role to a user'
)]
class AssignRoleCommand extends Command
{
    public function __construct(
        private UserRoleManager $userRoleManager,
        private EntityFactory $entityFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addArgument('role', InputArgument::REQUIRED, 'Role to assign (' . implode(', ', Roles::values()) . ')');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $roleStr = $input->getArgument('role');

        $role = Roles::tryFromString($roleStr);
        if (!$role) {
            $io->error(sprintf('Invalid role "%s". Available roles: %s', $roleStr, implode(', ', Roles::values())));
            return Command::FAILURE;
        }

        // Find user
        /** @var User|null $user */
        $user = $this->entityFactory->findOneBy(User::class, ['email' => $email]);

        if (!$user) {
            $io->error(sprintf('User with email "%s" not found.', $email));
            return Command::FAILURE;
        }

        if ($this->userRoleManager->hasRole($user->id, $role)) {
            $io->note(sprintf('User "%s" already has role "%s".', $email, $role->value));
            return Command::SUCCESS;
        }

        $this->userRoleManager->assignRole($user->id, $role);
        $io->success(sprintf('Role "%s" assigned to user "%s".', $role->value, $email));

        return Command::SUCCESS;
    }
}
