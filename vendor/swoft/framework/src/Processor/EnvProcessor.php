<?php

namespace Swoft\Processor;

use Dotenv\Environment\Adapter\EnvConstAdapter;
use Dotenv\Environment\Adapter\PutenvAdapter;
use Dotenv\Environment\Adapter\ServerConstAdapter;
use Dotenv\Environment\DotenvFactory;
use Dotenv\Dotenv;
use Swoft\Log\Helper\CLog;
use function alias;
use function basename;
use function dirname;
use function file_exists;

/**
 * Env processor
 *
 * @since 2.0
 */
class EnvProcessor extends Processor
{
    /**
     * Handler env process
     *
     * @return bool
     */
    public function handle(): bool
    {
        if (!$this->application->beforeEnv()) {
            CLog::warning('Stop env processor by beforeEnv return false');
            return false;
        }

        // 获取env文件路径
        $envFile = alias($this->application->getEnvFile());
        $path    = dirname($envFile);
        $name    = basename($envFile);

        // env不存在时不处理
        if (!file_exists($envFile)) {
            CLog::warning('Env file(%s) is not exist! skip load it', $envFile);
            return true;
        }

        // 使用DotenvFactory将env文件加载进系统
        $factory = new DotenvFactory([
            new EnvConstAdapter,
            new PutenvAdapter,
            new ServerConstAdapter
        ]);
        Dotenv::create($path, $name, $factory)->load();

        CLog::info('Env file(%s) is loaded', $envFile);

        return $this->application->afterEnv();
    }
}
