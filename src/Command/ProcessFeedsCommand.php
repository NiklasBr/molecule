<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FeedProcessor;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-feeds',
    description: 'Fetches, combines and caches ATOM/RSS feeds',
)]
class ProcessFeedsCommand extends Command
{
    public function __construct(
        private readonly FeedProcessor $feedProcessor
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->info('Processing feeds...');
            $this->feedProcessor->processFeeds();
            $io->success('Feeds processed successfully. Output stored in public/combined_feed.atom');
        } catch (InvalidArgumentException|Exception $e) {
            $io->error('An error occurred: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
