<?php

/*
 * This file is part of Gush.
 *
 * (c) Luis Cordova <cordoval@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Gush\Command;

use Gush\Model\BufferedOutput;
use Gush\Model\Question;
use Gush\Model\Questionary;
use Gush\Model\SymfonyDocumentationQuestionary;
use Gush\Model\SymfonyQuestionary;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gush\Feature\GitHubFeature;
use Gush\Feature\TableFeature;

/**
 * Launches a pull request
 *
 * @author Luis Cordova <cordoval@gmail.com>
 */
class PullRequestCreateCommand extends BaseCommand implements TableFeature, GitHubFeature
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('pull-request:create')
            ->setDescription('Launches a pull request')
            ->addArgument('base_branch', InputArgument::OPTIONAL, 'Name of the base branch to PR to', 'master')
            ->setHelp(
                <<<EOF
The <info>%command.name%</info> command gives a pat on the back to a PR's author with a random template:

    <info>$ gush %command.full_name% 12</info>
EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tableString = $this->getGithubTableString($input, $output);

        /** @var DialogHelper $dialog */
        $dialog = $this->getHelper('dialog');
        $validator = function ($answer) {
            $answer = trim($answer);
            if (empty($answer)) {
                throw new \RunTimeException('You need to provide a non empty title');
            }

            return $answer;
        };
        $title = $dialog->askAndValidate(
            $output,
            'PR Title:',
            $validator,
            false,
            null,
            null
        );
        $prNumber = $this->postPullRequest($input, $output, $title, $tableString);
        $output->writeln($prNumber['html_url']);

        return self::COMMAND_SUCCESS;
    }

    private function getGithubTableString(InputInterface $input, OutputInterface $output)
    {
        /** @var DialogHelper $dialog */
        $dialog = $this->getHelper('dialog');

        /** @var \Gush\Model\Question[] $questions */
        if (false === strpos($this->getHelper('git')->getRepoName(), 'docs')) {
            $questionary = new SymfonyQuestionary();
        } else {
            $questionary = new SymfonyDocumentationQuestionary();
        }

        $answers = [];
        /** @var Question $question */
        foreach ($questionary->getQuestions() as $question) {
            $statement = $question->getStatement() . ' ';
            if ($question->getDefault()) {
                $statement .= '[' . $question->getDefault() . '] ';
            }

            // @todo change this when on 2.5 to the new Question model
            $answers[] = [
                $question->getStatement(),
                $dialog->askAndValidate(
                    $output,
                    $statement,
                    $question->getValidator(),
                    $question->getAttempt(),
                    $question->getDefault(),
                    $question->getAutocomplete()
                )
            ];
        }

        $table = $this->getMarkdownTableHelper($questionary);
        $table->addRows($answers);

        $tableOutput = new BufferedOutput();
        $input->setOption('table-layout', 'github');
        $table->render($tableOutput);

        return $tableOutput->fetch();
    }

    private function getMarkdownTableHelper(Questionary $questionary)
    {
        $table = $this->getHelper('table');

        // adds headers from questionary
        $table->addRow($questionary->getHeaders());
        // adds rows --- | --- | ...
        $table->addRow(array_fill(0, count($questionary->getHeaders()), '---'));

        return $table;
    }

    private function postPullRequest(
        InputInterface $input,
        OutputInterface $output,
        $title,
        $description
    ) {
        $org = $input->getOption('org');
        $repo = $input->getOption('repo');

        $baseBranch = $input->getArgument('base_branch');

        $github = $this->getParameter('github');
        $username = $github['username'];
        $branchName = $this->getHelper('git')->getBranchName();

        $commands = [
            [
                'line' => sprintf('git remote add %s git@github.com:%s/%s.git', $username, $username, $repo),
                'allow_failures' => true
            ],
            [
                'line' => 'git remote update',
                'allow_failures' => false
            ],
            [
                'line' => sprintf('git push -u %s %s', $username, $branchName),
                'allow_failures' => false
            ]
        ];

        $this->getHelper('process')->runCommands($commands);

        $client = $this->getGithubClient();
        $pullRequest = $client
            ->api('pull_request')
            ->create(
                $org,
                $repo,
                [
                    'base'  => $org.':'.$baseBranch,
                    'head'  => $username.':'.$branchName,
                    'title' => $title,
                    'body'  => $description,
                ]
            )
        ;

        return $pullRequest;
    }
}
