<?php

/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Bundle\EzPublishCoreBundle\Command;

use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\Search\Common\Indexer;
use eZ\Publish\Core\Search\Common\IncrementalIndexer;
use eZ\Publish\SPI\Persistence\Content\Location\Handler as LocationHandler;
use eZ\Publish\SPI\Search\Content\IndexerGateway;
use Generator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use RuntimeException;
use DateTime;

class ReindexCommand extends ContainerAwareCommand
{
    /** @var \eZ\Publish\Core\Search\Common\Indexer|\eZ\Publish\Core\Search\Common\IncrementalIndexer */
    private $searchIndexer;

    /** @var string */
    private $phpPath;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var string */
    private $siteaccess;

    /** @var string */
    private $env;

    /** @var bool */
    private $isDebug;

    /** @var string */
    private $projectDir;

    /** @var \eZ\Publish\SPI\Search\Content\IndexerGateway */
    private $gateway;

    /** @var \eZ\Publish\SPI\Persistence\Content\Location\Handler */
    private $locationHandler;

    public function __construct(IndexerGateway $gateway, LocationHandler $locationHandler)
    {
        $this->gateway = $gateway;
        $this->locationHandler = $locationHandler;

        parent::__construct();
    }

    /**
     * Initialize objects required by {@see execute()}.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->searchIndexer = $this->getContainer()->get('ezpublish.spi.search.indexer');
        $this->logger = $this->getContainer()->get('logger');
        $this->env = $this->getContainer()->getParameter('kernel.environment');
        $this->isDebug = $this->getContainer()->getParameter('kernel.debug');
        $this->projectDir = $this->getContainer()->getParameter('kernel.project_dir');
        if (!$this->searchIndexer instanceof Indexer) {
            throw new RuntimeException(
                sprintf(
                    'Expected to find Search Engine Indexer but found "%s" instead',
                    get_parent_class($this->searchIndexer)
                )
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('ezplatform:reindex')
            ->setDescription('Recreate or Refresh search engine index')
            ->addOption(
                'iteration-count',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Number of objects to be indexed in a single iteration, for avoiding using too much memory',
                50
            )->addOption(
                'no-commit',
                null,
                InputOption::VALUE_NONE,
                'Do not commit after each iteration'
            )->addOption(
                'no-purge',
                null,
                InputOption::VALUE_NONE,
                'Do not purge before indexing'
            )->addOption(
                'since',
                null,
                InputOption::VALUE_OPTIONAL,
                'Refresh changes since a given time, any format understood by DateTime. Implies "no-purge", can not be combined with "content-ids" or "subtree"'
            )->addOption(
                'content-ids',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of content id\'s to refresh (deleted/updated/added). Implies "no-purge", can not be combined with "since" or "subtree"'
            )->addOption(
                'subtree',
                null,
                InputOption::VALUE_OPTIONAL,
                'Location Id to index subtree of (incl self). Implies "no-purge", can not be combined with "since" or "content-ids"'
            )->addOption(
                'processes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of child processes to run in parallel for iterations, if set to "auto" it will set to number of CPU cores -1, set to "1" or "0" to disable',
                'auto'
            )->setHelp(
                <<<EOT
The command <info>%command.name%</info> indexes current configured database in configured search engine index.


Example usage:
- Refresh (add/update) index changes since yesterday:
  <comment>ezplatform:reindex --since=yesterday</comment>
  See: http://php.net/manual/en/datetime.formats.php

- Refresh (add/update/remove) index on a set of content id's:
  <comment>ezplatform:reindex --content-ids=2,34,68</comment>

- Refresh (add/update) index of a subtree:
  <comment>ezplatform:reindex --subtree=45</comment>

- Refresh (add/update) index disabling use of child proccesses and initial purging,
  & let search engine handle commits using auto commit:
  <comment>ezplatform:reindex --no-purge --no-commit --processes=0</comment>

EOT
            );
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $commit = !$input->getOption('no-commit');
        $iterationCount = $input->getOption('iteration-count');
        $this->siteaccess = $input->getOption('siteaccess');
        if (!is_numeric($iterationCount) || (int) $iterationCount < 1) {
            throw new InvalidArgumentException('--iteration-count', "Option should be > 0, got '{$iterationCount}'");
        }

        if (!$this->searchIndexer instanceof IncrementalIndexer) {
            $output->writeln(<<<EOT
DEPRECATED:
Running indexing against an Indexer that has not been updated to use IncrementalIndexer abstract.

Options that won't be taken into account:
- since
- content-ids
- subtree
- processes
- no-purge
EOT
            );
            $this->searchIndexer->createSearchIndex($output, (int) $iterationCount, !$commit);
        } else {
            $output->writeln('Re-indexing started for search engine: ' . $this->searchIndexer->getName());
            $output->writeln('');

            return $this->indexIncrementally($input, $output, $iterationCount, $commit);
        }

        return 0;
    }

    /**
     * @param int $iterationCount
     * @param bool $commit
     *
     * @return int
     *
     * @throws \Exception
     */
    protected function indexIncrementally(InputInterface $input, OutputInterface $output, $iterationCount, $commit)
    {
        if ($contentIds = $input->getOption('content-ids')) {
            $contentIds = explode(',', $contentIds);
            $output->writeln(sprintf(
                'Indexing list of content id\'s (%s)' . ($commit ? ', with commit' : ''),
                \count($contentIds)
            ));

            return $this->searchIndexer->updateSearchIndex($contentIds, $commit);
        }

        if ($since = $input->getOption('since')) {
            $count = $this->gateway->countContentSince(new DateTime($since));
            $generator = $this->gateway->getContentSince(new DateTime($since), $iterationCount);
            $purge = false;
        } elseif ($locationId = (int) $input->getOption('subtree')) {
            /** @var \eZ\Publish\SPI\Persistence\Content\Location\Handler */
            $location = $this->locationHandler->load($locationId);
            $count = $this->gateway->countContentInSubtree($location->pathString);
            $generator = $this->gateway->getContentInSubtree($location->pathString, $iterationCount);
            $purge = false;
        } else {
            $count = $this->gateway->countAllContent();
            $generator = $this->gateway->getAllContent($iterationCount);
            $purge = !$input->getOption('no-purge');
        }

        if (!$count) {
            $output->writeln('<error>Could not find any items to index, aborting.</error>');

            return 1;
        }

        $iterations = ceil($count / $iterationCount);
        $processes = $input->getOption('processes');
        $processCount = $processes === 'auto' ? $this->getNumberOfCPUCores() - 1 : (int) $processes;
        $processCount = min($iterations, $processCount);
        $processMessage = $processCount > 1 ? "using $processCount parallel child processes" : 'using single (current) process';

        if ($purge) {
            $output->writeln('Purging index...');
            $this->searchIndexer->purge();

            $output->writeln(
                "<info>Re-Creating index for {$count} items across $iterations iteration(s), $processMessage:</info>"
            );
        } else {
            $output->writeln(
                "<info>Refreshing index for {$count} items across $iterations iteration(s), $processMessage:</info>"
            );
        }

        $progress = new ProgressBar($output);
        $progress->start($iterations);

        if ($processCount > 1) {
            $this->runParallelProcess(
                $progress,
                $generator,
                (int)$processCount,
                $commit
            );
        } else {
            // if we only have one process, or less iterations to warrant running several, we index it all inline
            foreach ($generator as $contentIds) {
                $this->searchIndexer->updateSearchIndex($contentIds, $commit);
                $progress->advance(1);
            }
        }

        $progress->finish();
        $output->writeln('');
        $output->writeln('Finished re-indexing');
        $output->writeln('');
        // clear leftover progress bar parts
        $progress->clear();

        return 0;
    }

    /**
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     */
    private function runParallelProcess(
        ProgressBar $progress,
        Generator $generator,
        int $processCount,
        bool $commit
    ): void {
        /** @var \Symfony\Component\Process\Process[]|null[] */
        $processes = array_fill(0, $processCount, null);
        do {
            /** @var \Symfony\Component\Process\Process $process */
            foreach ($processes as $key => $process) {
                if ($process !== null && $process->isRunning()) {
                    continue;
                }

                if ($process !== null) {
                    // One of the processes just finished, so we increment progress bar
                    $progress->advance(1);

                    if (!$process->isSuccessful()) {
                        $this->logger->error(
                            sprintf(
                                'Child indexer process returned: %s - %s',
                                $process->getExitCodeText(),
                                $process->getOutput()
                            )
                        );
                    }
                }

                if (!$generator->valid()) {
                    unset($processes[$key]);
                    continue;
                }

                $processes[$key] = $this->getPhpProcess($generator->current(), $commit);
                $processes[$key]->start();
                $generator->next();
            }

            if (!empty($processes)) {
                sleep(1);
            }
        } while (!empty($processes));
    }

    /**
     * @param array $contentIds
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException
     */
    private function getPhpProcess(array $contentIds, bool $commit): Process
    {
        if (empty($contentIds)) {
            throw new InvalidArgumentException('--content-ids', '$contentIds can not be empty');
        }

        $consolePath = file_exists(sprintf('%s/bin/console', $this->projectDir)) ? sprintf('%s/bin/console', $this->projectDir) : sprintf('%s/app/console', $this->projectDir);
        $subProcessArgs = [
            $consolePath,
            'ezplatform:reindex',
            '--content-ids=' . implode(',', $contentIds),
            '--env=' . $this->env,
        ];
        if ($this->siteaccess) {
            $subProcessArgs[] = '--siteaccess=' . $this->siteaccess;
        }
        if (!$this->isDebug) {
            $subProcessArgs[] = '--no-debug';
        }

        $process = new ProcessBuilder($subProcessArgs);
        $process->setTimeout(null);
        $process->setPrefix($this->getPhpPath());

        if (!$commit) {
            $process->add('--no-commit');
        }

        return $process->getProcess();
    }

    /**
     * @return string
     */
    private function getPhpPath()
    {
        if ($this->phpPath) {
            return $this->phpPath;
        }

        $phpFinder = new PhpExecutableFinder();
        $this->phpPath = $phpFinder->find();
        if (!$this->phpPath) {
            throw new RuntimeException(
                'The php executable could not be found, it\'s needed for executing parable sub processes, so add it to your PATH environment variable and try again'
            );
        }

        return $this->phpPath;
    }

    /**
     * @return int
     */
    private function getNumberOfCPUCores()
    {
        $cores = 1;
        if (is_file('/proc/cpuinfo')) {
            // Linux (and potentially Windows with linux sub systems)
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            $cores = \count($matches[0]);
        } elseif (\DIRECTORY_SEPARATOR === '\\') {
            // Windows
            if (($process = @popen('wmic cpu get NumberOfCores', 'rb')) !== false) {
                fgets($process);
                $cores = (int) fgets($process);
                pclose($process);
            }
        } elseif (($process = @popen('sysctl -a', 'rb')) !== false) {
            // *nix (Linux, BSD and Mac)
            $output = stream_get_contents($process);
            if (preg_match('/hw.ncpu: (\d+)/', $output, $matches)) {
                $cores = (int) $matches[1][0];
            }
            pclose($process);
        }

        return $cores;
    }
}
