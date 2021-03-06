<?php

/*
 * This file was part of the Doctrine Fixtures Bundle
 *
 * The code was originally distributed inside the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 * (c) Doctrine Project
 * (c) Esports.cz
 *
 */
namespace Esports\Doctrine\Fixtures\Command;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader as DataFixturesLoader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\DBAL\Sharding\PoolingShardConnection;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Load data fixtures from bundles.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jonathan H. Wage <jonwage@gmail.com>
 */
class LoadDataFixturesDoctrineCommand extends Command
{

	/**
	 * @var array
	 */
	private $paths;

	/**
	 * @param array $paths
	 */
	public function __construct(array $paths)
	{
		parent::__construct(null);
		$this->paths = $paths;
	}

	protected function configure()
	{
		$this
			->setName('doctrine:fixtures:load')
			->setDescription('Load data fixtures to your database.')
			->addOption('fixtures', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The directory to load data fixtures from.')
			->addOption('append', null, InputOption::VALUE_NONE, 'Append the data fixtures instead of deleting all data from the database first.')
			->addOption('em', null, InputOption::VALUE_REQUIRED, 'The entity manager to use for this command.')
			->addOption('shard', null, InputOption::VALUE_REQUIRED, 'The shard connection to use for this command.')
			->addOption('purge-with-truncate', null, InputOption::VALUE_NONE, 'Purge data by using a database-level TRUNCATE statement')
			->addOption('multiple-transactions', null, InputOption::VALUE_NONE, 'Use one transaction per fixture file instead of a single transaction for all')
			->setHelp(<<<EOT
The <info>doctrine:fixtures:load</info> command loads data fixtures from your bundles:

  <info>./app/console doctrine:fixtures:load</info>

You can also optionally specify the path to fixtures with the <info>--fixtures</info> option:

  <info>./app/console doctrine:fixtures:load --fixtures=/path/to/fixtures1 --fixtures=/path/to/fixtures2</info>

If you want to append the fixtures instead of flushing the database first you can use the <info>--append</info> option:

  <info>./app/console doctrine:fixtures:load --append</info>

By default Doctrine Data Fixtures uses DELETE statements to drop the existing rows from
the database. If you want to use a TRUNCATE statement instead you can use the <info>--purge-with-truncate</info> flag:

  <info>./app/console doctrine:fixtures:load --purge-with-truncate</info>
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$em = $this->getHelper('em')->getEntityManager();

		if ($input->isInteractive() && !$input->getOption('append')) {
			if (!$this->askConfirmation($input, $output, '<question>Careful, database will be purged. Do you want to continue y/N ?</question>', false)) {
				return;
			}
		}

		if ($input->getOption('shard')) {
			if (!$em->getConnection() instanceof PoolingShardConnection) {
				throw new LogicException(sprintf("Connection of EntityManager '%s' must implement shards configuration.", $input->getOption('em')));
			}

			$em->getConnection()->connect($input->getOption('shard'));
		}

		$dirOrFile = $input->getOption('fixtures');
		if ($dirOrFile) {
			$paths = is_array($dirOrFile) ? $dirOrFile : array($dirOrFile);
		} else {
			$paths = $this->paths;
		}

		$loader = new DataFixturesLoader();
		foreach ($paths as $path) {
			if (is_dir($path)) {
				$loader->loadFromDirectory($path);
			} elseif (is_file($path)) {
				$loader->loadFromFile($path);
			}
		}
		$fixtures = $loader->getFixtures();
		if (!$fixtures) {
			throw new InvalidArgumentException(
			sprintf('Could not find any fixtures to load in: %s', "\n\n- " . implode("\n- ", $paths))
			);
		}
		$purger = new ORMPurger($em);
		$purger->setPurgeMode($input->getOption('purge-with-truncate') ? ORMPurger::PURGE_MODE_TRUNCATE : ORMPurger::PURGE_MODE_DELETE);
		$executor = new ORMExecutor($em, $purger);
		$executor->setLogger(function ($message) use ($output) {
			$output->writeln(sprintf('  <comment>></comment> <info>%s</info>', $message));
		});
		$executor->execute($fixtures, $input->getOption('append'), $input->getOption('multiple-transactions'));
	}

	/**
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 * @param string          $question
	 * @param bool            $default
	 *
	 * @return bool
	 */
	private function askConfirmation(InputInterface $input, OutputInterface $output, $question, $default)
	{
		if (!class_exists('Symfony\Component\Console\Question\ConfirmationQuestion')) {
			$dialog = $this->getHelperSet()->get('dialog');

			return $dialog->askConfirmation($output, $question, $default);
		}

		$questionHelper = $this->getHelperSet()->get('question');
		$question = new ConfirmationQuestion($question, $default);

		return $questionHelper->ask($input, $output, $question);
	}
}
