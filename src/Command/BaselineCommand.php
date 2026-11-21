<?php

declare(strict_types=1);

namespace M2Performance\Command;

use M2Performance\Service\AnalysisRunner;
use M2Performance\Service\BaselineManager;
use M2Performance\Service\StoreContext;
use M2Performance\Service\ToolConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Baseline management: save named performance snapshots and compare
 * later runs against them for regression detection.
 *
 * Usage:
 *   m2-performance baseline --save=pre-deploy-2.4.7
 *   m2-performance baseline --list
 *   m2-performance baseline --compare=pre-deploy-2.4.7
 */
class BaselineCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('baseline')
            ->setDescription('Save, list and compare performance baselines for regression detection')
            ->addOption('save', null, InputOption::VALUE_REQUIRED, 'Run analysis and save results as a named baseline')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List stored baselines')
            ->addOption('compare', null, InputOption::VALUE_REQUIRED, 'Run analysis and compare against a named baseline')
            ->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Path to Magento root', getcwd())
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Analysis profile (basic, full, security)', 'full')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to .m2perf.json configuration file')
            ->addOption(
                'allow-dev-mode',
                null,
                InputOption::VALUE_NONE,
                'Developer-mode-aware analysis (match the review run you compare against)'
            )
            ->addOption(
                'fail-on-regression',
                null,
                InputOption::VALUE_NONE,
                'Exit with code 1 on regression or when the score is below thresholds.min_score (CI/CD gate)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magentoRoot = (string)$input->getOption('magento-root');
        $manager = new BaselineManager($magentoRoot);

        if ($input->getOption('list') === true) {
            return $this->renderList($manager, $output);
        }

        $saveName = $input->getOption('save');
        $compareName = $input->getOption('compare');

        if ($saveName === null && $compareName === null) {
            $output->writeln('<comment>Specify one of: --save=<name>, --compare=<name>, --list</comment>');
            return Command::INVALID;
        }

        $configPath = $input->getOption('config');
        $toolConfig = new ToolConfigLoader(
            is_string($configPath) === true ? $configPath : null,
            $magentoRoot
        );

        $output->writeln('<info>Running analysis...</info>');
        $runner = new AnalysisRunner($magentoRoot, $toolConfig);
        $runner->configureMode(
            StoreContext::forRoot($magentoRoot)->getMagentoMode(),
            $input->getOption('allow-dev-mode') === true
        );
        $recommendations = $runner->run((string)$input->getOption('profile'), null, true);
        $serialized = $runner->toArray($recommendations);
        $score = $runner->computeScore($recommendations);

        $output->writeln(sprintf(
            'Score: <info>%.1f</info> (Grade %s) — %d high, %d medium, %d low',
            $score['score'],
            $score['grade'],
            $score['counts']['high'],
            $score['counts']['medium'],
            $score['counts']['low']
        ));

        if ($saveName !== null) {
            $path = $manager->save((string)$saveName, $serialized, $score, [
                'profile' => (string)$input->getOption('profile'),
                'analysis_mode' => $runner->getAnalysisMode(),
            ]);
            $output->writeln(sprintf('<info>Baseline saved:</info> %s', $path));
            return Command::SUCCESS;
        }

        $comparison = $manager->compare(
            (string)$compareName,
            $serialized,
            $score,
            (string)$input->getOption('profile'),
            $runner->getAnalysisMode()
        );
        if (isset($comparison['error']) === true) {
            $output->writeln('<error>' . $comparison['error'] . '</error>');
            return Command::FAILURE;
        }

        $this->renderComparison($comparison, $output);

        if ($input->getOption('fail-on-regression') === true && $comparison['is_regression'] === true) {
            $output->writeln('<error>Regression detected — failing build.</error>');
            return Command::FAILURE;
        }

        $minScore = $toolConfig->getMinScoreThreshold();
        if ($input->getOption('fail-on-regression') === true && $minScore !== null && $score['score'] < $minScore) {
            $output->writeln(sprintf(
                '<error>Score %.1f is below thresholds.min_score %.1f - failing build.</error>',
                $score['score'],
                $minScore
            ));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function renderList(BaselineManager $manager, OutputInterface $output): int
    {
        $baselines = $manager->listBaselines();

        if (count($baselines) === 0) {
            $output->writeln('<comment>No baselines stored yet. Create one with: baseline --save=<name></comment>');
            $output->writeln('Storage: ' . $manager->getStorageDir());
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Created', 'Score', 'Grade']);
        foreach ($baselines as $baseline) {
            $table->addRow([
                $baseline['name'],
                $baseline['created_at'],
                number_format($baseline['score'], 1),
                $baseline['grade'],
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $comparison
     */
    private function renderComparison(array $comparison, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>=== Baseline Comparison ===</info>');
        $output->writeln($comparison['summary']);
        if (isset($comparison['warning']) === true) {
            $output->writeln('<comment>' . $comparison['warning'] . '</comment>');
        }
        $output->writeln('');

        $resolved = $comparison['resolved_issues'];
        if (count($resolved) > 0) {
            $output->writeln(sprintf('<info>Resolved (%d):</info>', count($resolved)));
            foreach ($resolved as $issue) {
                $output->writeln(sprintf('  ✓ [%s] %s', $issue['area'], $issue['title']));
            }
            $output->writeln('');
        }

        $introduced = $comparison['introduced_issues'];
        if (count($introduced) > 0) {
            $output->writeln(sprintf('<comment>Introduced (%d):</comment>', count($introduced)));
            foreach ($introduced as $issue) {
                $output->writeln(sprintf(
                    '  ✗ [%s][%s] %s',
                    $issue['area'],
                    $issue['priority_label'],
                    $issue['title']
                ));
            }
            $output->writeln('');
        }

        if ($comparison['is_regression'] === true) {
            $output->writeln('<error>Status: REGRESSION</error>');
        } else {
            $output->writeln('<info>Status: OK (no regression)</info>');
        }
    }
}
