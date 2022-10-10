<?php

namespace STM\ElasticaFastPopulateBundle\Command;

use Enqueue\Symfony\Consumption\LimitsExtensionsCommandTrait;
use FOS\ElasticaBundle\Persister\InPlacePagerPersister;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Class FastPopulateCommand permet faire une réindexation des données ElasticSearch en optimisant le populate
 * On va utiliser des processus en parallèle en passant par des queues
 * Cette commande prend en paramètre les mêmes que fos:elastica:populate ainsi que pour les queues
 * @package App\Command
 */
class FastPopulateCommand extends Command
{
    use LimitsExtensionsCommandTrait;

    const DEFAULT_NAME = 'stm:fast-populate:populate';

    /** @var Process[] Liste des process de la queue */
    private array $consumeProcess = [];

    public function __construct(
    )
    {
        parent::__construct(self::DEFAULT_NAME);
    }
    /**
     * Configuration de la commande
     */
    protected function configure()
    {
        $this->configureLimitsExtensions();

        $this
            ->setDescription('Populates search indexes from providers with queues')
            ->addOption('index', null, InputOption::VALUE_OPTIONAL, 'The index to repopulate')
            ->addOption('no-reset', null, InputOption::VALUE_NONE, 'Do not reset index before populating')
            ->addOption('no-delete', null, InputOption::VALUE_NONE, 'Do not delete index after populate')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep time between persisting iterations (microseconds)', 0)
            ->addOption('ignore-errors', null, InputOption::VALUE_NONE, 'Do not stop on errors')
            ->addOption('no-overwrite-format', null, InputOption::VALUE_NONE, 'Prevent this command from overwriting ProgressBar\'s formats')
            ->addOption('first-page', null, InputOption::VALUE_REQUIRED, 'The pager\'s page to start population from. Including the given page.', 1)
            ->addOption('last-page', null, InputOption::VALUE_REQUIRED, 'The pager\'s page to end population on. Including the given page.', null)
            ->addOption('max-per-page', null, InputOption::VALUE_REQUIRED, 'The pager\'s page size', 100)
            ->addOption('pager-persister', null, InputOption::VALUE_REQUIRED, 'The pager persister to be used to populate the index', InPlacePagerPersister::NAME)
            ->addOption('nb-subprocess', null, InputOption::VALUE_REQUIRED, 'The number of subprocess for the queue', 2);

    }

    /**
     * Permet de faire appel au service pour effectuer la reprise de données
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $index = $input->getOption('index');
        $nbSubProcess = $input->getOption('nb-subprocess');

        $optionsPopulate = [
            'delete' => !$input->getOption('no-delete'),
            'reset' => !$input->getOption('no-reset'),
            'ignore_errors' => $input->getOption('ignore-errors'),
            'sleep' => $input->getOption('sleep'),
            'first_page' => $input->getOption('first-page'),
            'max_per_page' => $input->getOption('max-per-page'),
        ];

        if ($input->getOption('last-page')) {
            $optionsPopulate['last_page'] = $input->getOption('last-page');
        }

        $optionsConsume = [
            'message-limit' => $input->getOption('message-limit'),
            'time-limit' => $input->getOption('time-limit'),
            'memory-limit' => $input->getOption('memory-limit'),
            'niceness' => $input->getOption('niceness')
        ];

        $io = new SymfonyStyle($input, $output);

        $io->info("Start populate command");
        $populateProcess = $this->createAndStartPopulateProcess($index, $optionsPopulate, $io);

        $io->info("Create $nbSubProcess process");
        for ($i = 0; $i < $nbSubProcess; $i++) {
            $this->consumeProcess[] = $this->createAndStartConsumeProcess($optionsConsume);
        }

        while ($populateProcess->isRunning()) {
            usleep(1_000_000);// On attend une seconde pour éviter de surcharger le thread de traitement inutile
            foreach ($this->consumeProcess as $key => $process) {
                if ($process->isRunning()) {
                    continue;
                }
                if (($this->handleEndProcess($process, $io, $key)) != 0) {
                    $populateProcess->stop();
                    $io->error("Stop all subprocess");
                    $this->stopAllConsumeProcess();
                    return Command::FAILURE;
                }
                $io->text("Compute process $key terminated, start a new one");
                $this->consumeProcess[$key] = $this->createAndStartConsumeProcess($optionsConsume);
            }
        }
        if (!$populateProcess->isSuccessful()) {
            $io->error("Populate command terminate with error");
            $io->error("Stop all subprocess");
            $this->stopAllConsumeProcess();
            return Command::FAILURE;
        }
        $io->text("Stop all subprocess");
        $io->success("Populate command successfully terminated");
        $this->stopAllConsumeProcess();
        return Command::SUCCESS;
    }

    /**
     * Permet d'arrêter tous les sous process de la queue
     * @return void
     */
    private function stopAllConsumeProcess() {
        foreach ($this->consumeProcess as $process) {
            $process->stop();
        }
    }

    private function createAndStartConsumeProcess(array $options): Process
    {
        $phpBinaryPath = (new PhpExecutableFinder())->find();
        $command = [$phpBinaryPath];
        if (!is_null($options["memory-limit"])) {
            $command[] = "-d";
            $command[] = "memory_limit=-1";
        }
        $command[] = '-f';
        $command[] = 'bin/console';
        $command[] = 'enqueue:consume';
        foreach ($options as $key => $option) {
            if (is_null($option)) {
                continue;
            }
            $command[] = "--$key=$option";
        }

        $process = new Process($command);

        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->start(null, ['XDEBUG_MODE' => 'off']);

        return $process;
    }

    private function createAndStartPopulateProcess(?string $index, array $options, SymfonyStyle $io): Process
    {
        $phpBinaryPath = (new PhpExecutableFinder())->find();
        $command = [
            $phpBinaryPath,
            '-f',
            'bin/console',
            'fos:elastica:populate',
            '--pager-persister=queue',
            '--ansi',
            '--sleep=' . $options["sleep"],
            '--first-page=' . $options["first_page"],
            '--max-per-page=' . $options["max_per_page"]];
        if (!is_null($index)) {
            $command[] = "--index=$index";
        }
        if (array_key_exists("last_page", $options)) {
            $command[] = "--last-page=" . $options['last_page'];
        }
        if (!$options['delete']) {
            $command[] = "--no-delete";
        }
        if (!$options['reset']) {
            $command[] = "--no-reset";
        }
        if ($options['ignore_errors']) {
            $command[] = "--ignore-errors";
        }

        $process = new Process($command);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->start(function ($type, $buffer) use ($io) {
            $io->text($buffer);
        }, ['XDEBUG_MODE' => 'off']);

        return $process;
    }

    protected function handleEndProcess(Process $process, SymfonyStyle $io, int $key): int
    {
        if (!$process->isSuccessful()) {
            $io->error("Erreur du process $key, exit code : " . $process->getExitCode() ." : " . $process->getOutput());
            return $process->getExitCode();
        }
        return 0;
    }

}
