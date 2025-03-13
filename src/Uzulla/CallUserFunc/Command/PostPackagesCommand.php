<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Uzulla\CallUserFunc\App\AppFactory;

/**
 * パッケージ投稿コマンド
 */
class PostPackagesCommand extends Command
{
    /**
     * コマンド名
     */
    protected static $defaultName = 'app:post-packages';
    
    /**
     * コマンドの設定
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Packagist.orgの新着パッケージをBlueSkyに投稿します')
            ->setHelp('このコマンドはPackagist.orgのRSSフィードから新着パッケージを取得し、BlueSkyに投稿します')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                '投稿する最大パッケージ数',
                5
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                '実際に投稿せずに処理内容を表示するのみ'
            );
    }
    
    /**
     * コマンドの実行
     *
     * @param InputInterface $input 入力
     * @param OutputInterface $output 出力
     * @return int 終了コード
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = AppFactory::create();
        
        return $app->run($input, $output);
    }
}
