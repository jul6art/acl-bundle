<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Command;

use Jul6Art\AclBundle\Doctrine\PermissionSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * The roles' defaults where nobody has decided yet (ADR-0001). Safe at every deployment: it never reopens a cell
 * an administrator unticked. `app:permissions:seed`, the name the applications' copies had, stays an alias:
 * deployment hooks call it.
 */
#[AsCommand(name: 'acl:permissions:seed', description: 'Writes the roles\' default permissions nobody has decided yet', aliases: ['app:permissions:seed'])]
final class SeedPermissionsCommand extends Command
{
    public function __construct(
        private readonly PermissionSeeder $seeder,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $written = $this->seeder->seed();

        new SymfonyStyle($input, $output)->success(sprintf('%d default permission(s) written; a decision taken on the grid is kept.', $written));

        return Command::SUCCESS;
    }
}
