<?php

namespace App\EnvVarProcessor;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

class SiteHostnameEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = $getEnv($name);
        $parts = is_string($value) ? parse_url('http://'.$value) : false;

        if (false === $parts || !isset($parts['host']) || '' === $parts['host']
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/\s/', $value)
        ) {
            throw new RuntimeException(sprintf('Environment variable "%s" must contain a hostname with an optional port.', $name));
        }

        return $parts['host'];
    }

    public static function getProvidedTypes(): array
    {
        return ['site_hostname' => 'string'];
    }
}
