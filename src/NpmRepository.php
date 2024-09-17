<?php

namespace Igorgoroshit\Ppm;

use Composer\Cache;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\LoaderInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\StabilityFilter;
use Composer\Package\Version\VersionParser;
use Composer\PartialComposer;
use Composer\Pcre\Preg;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySecurityException;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\Url;
use ErrorException;
use InvalidArgumentException;
use UnexpectedValueException;


class NpmRepository implements ConfigurableRepositoryInterface, RepositoryInterface
{
    protected array $options = [];
    protected array $repoConfig;
    protected PartialComposer $composer;
    protected IOInterface $io;
    protected Config $config;
    protected HttpDownloader $httpDownloader;
    protected ?EventDispatcher $eventDispatcher;
    protected LoaderInterface $loader;
    protected array $packages = [];
    protected array $packageMap = [];
    protected Cache $cache;


    protected string $url         = 'https://registry.npmjs.org';
    protected string $lazyLoadUrl = 'https://registry.npmjs.org/%package%';
    protected string $searchUrl   = 'https://www.npmjs.com/search/suggestions?q=%query%';


    public function __construct(array $repoConfig, PartialComposer $composer, IOInterface $io, Config $config, HttpDownloader $httpDownloader, ?EventDispatcher $eventDispatcher)
    {
        if (isset($repoConfig['options'])) {
            $this->options = $repoConfig['options'];
        }
        if (isset($repoConfig['url'])) {
            $this->url = $repoConfig['url'];
        }
        if (isset($repoConfig['lazy-load-url'])) {
            $this->lazyLoadUrl = $repoConfig['lazy-load-url'];
        }
        if (isset($repoConfig['search-url'])) {
            $this->searchUrl = $repoConfig['search-url'];
        }
        $this->repoConfig = $repoConfig;
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $config;
        $this->httpDownloader = $httpDownloader;
        $this->eventDispatcher = $eventDispatcher;
        $this->loader = new ArrayLoader();
        $this->cache = new Cache($io, $config->get('cache-repo-dir') . '/' . Preg::replace('{[^a-z0-9.]}i', '-', Url::sanitize($this->getUrl())), 'a-z0-9.$~');
        $this->cache->setReadOnly($config->get('cache-read-only'));
    }

    public function getRepoName(): string
    {
        return 'npmjs.org (' . $this->getUrl() . ')';
    }

    public function getRepoType(): string
    {
        return 'npm';
    }

    public function getRepoConfig(): array
    {
        return $this->repoConfig;
    }


    public function count(): int
    {
        return count($this->packages);
    }


    public function hasPackage(PackageInterface $package): bool
    {
        return isset($this->packageMap[$package->getUniqueName()]);
    }


    public function findPackage(string $name, $constraint): ?BasePackage
    {
        $name = strtolower($name);

        if (!$constraint instanceof ConstraintInterface) {
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($constraint);
        }

        foreach ($this->getPackages() as $package) {
            if ($name === $package->getName()) {
                $pkgConstraint = new Constraint('==', $package->getVersion());
                if ($constraint->matches($pkgConstraint)) {
                    return $package;
                }
            }
        }

        return null;
    }


    public function findPackages(string $name, $constraint = null): array
    {
        // normalize name
        $name = strtolower($name);
        $packages = [];

        if (null !== $constraint && !$constraint instanceof ConstraintInterface) {
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($constraint);
        }

        foreach ($this->getPackages() as $package) {
            if ($name === $package->getName()) {
                if (null === $constraint || $constraint->matches(new Constraint('==', $package->getVersion()))) {
                    $packages[] = $package;
                }
            }
        }

        return $packages;
    }


    public function getPackages(): array
    {
        return $this->packages;
    }


    public function addPackage(PackageInterface $package): void
    {
        if (!$package instanceof BasePackage) {
            throw new \InvalidArgumentException('Only subclasses of BasePackage are supported');
        }
        $package->setRepository($this);
        $this->packages[] = $package;

        if ($package instanceof AliasPackage) {
            $aliasedPackage = $package->getAliasOf();
            if (null === $aliasedPackage->getRepository()) {
                $this->addPackage($aliasedPackage);
            }
        }
    }

    protected function getNpmPackageName($name) {
        $realName  = $this->revertName($name);
        $cleanName = str_replace('npm-asset/', '', $realName);
        return $cleanName;
    }

    public function loadPackages(
        array $packageNameMap, 
        array $acceptableStabilities, 
        array $stabilityFlags, 
        array $alreadyLoaded = []): array
    {
        $namesFound = [];
        $packages   = [];

        foreach ($packageNameMap as $name => $constraint) {

            if (!Preg::match('#^npm-asset/#', $name)) {
                continue;
            }

            unset($data);

            $npmName    = $this->getNpmPackageName($name);
            $cleanName  = str_replace('npm-asset/', '', $name);
            try {
                if (!isset($this->packageMap[$name])) {
                   
                    $url = str_replace('%package%', $npmName, $this->getLazyLoadUrl());

                    if ($cachedData = $this->cache->read($this->getRepoType(). '-' . $cleanName . '.json')) {
                        $cachedData = json_decode($cachedData, true);
                        if (($age = $this->cache->getAge($this->getRepoType(). '-' . $cleanName . '.json')) && $age <= 900) {
                            $data = $cachedData;
                        } elseif (isset($cachedData['last-modified'])) {
                            $response = $this->fetchFileIfLastModified($url, $this->getRepoType(). '-' . $cleanName . '.json', $cachedData['last-modified']);
                            $data = true === $response ? $cachedData : $response;
                        }
                    }

                    if (!isset($data)) {
                        $data = $this->fetchFile($url, $this->getRepoType(). '-' . $cleanName . '.json', true);
                    }
                    unset($data['last-modified']);

                    $this->packageMap[$name] = $url;

                    foreach ($this->convertPackage($data) as $item) {
                        $this->addPackage($this->loader->load($item));
                    }
                }

                foreach ($this->packages as $package) {
                    /** @var \Composer\Package\CompletePackage $package */
                    if ($name === $package->getName() && $this->isVersionAcceptable(
                            $constraint,
                            $package->getName(),
                            ['version' => $package->getPrettyVersion(), 'version_normalized' => $package->getVersion()],
                            $acceptableStabilities,
                            $stabilityFlags)) {
                        $namesFound[$package->getName()] = true;
                        $packages[] = $package;
                    }
                }
            } catch (ErrorException $e) {
                continue;
            } catch (\Seld\JsonLint\ParsingExceptio $e) {
                continue;
            } catch (InvalidArgumentException $e) {
                continue;
            }
        }

        return [
            'namesFound' => $namesFound,
            'packages' => $packages
        ];
    }


    public function search(string $query, int $mode = 0, ?string $type = null): array
    {
        if (null === ($searchUrl = $this->getSearchUrl()) || $mode === self::SEARCH_VENDOR) {
            return [];
        }

        $searchUrl = str_replace('%query%', $query, $searchUrl);
        $data = $this->fetchFile($searchUrl);
        $results = [];

        foreach ($data as $item) {
            $results[] = $this->convertResultItem($item);
        }

        return $results;
    }

    public function getProviders(string $packageName)
    {
        // TODO: Implement getProviders() method.
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function getLazyLoadUrl(): ?string {
        return $this->lazyLoadUrl;
    }

    public function getSearchUrl(): ?string {
        return $this->searchUrl;
    }


    protected  function convertName($name)
    {
        if (0 === strpos($name, '@') && false !== strpos($name, '/')) {
            $name = ltrim(str_replace('/', '--', $name), '@');
        }

        return $name;
    }

    protected  function revertName($name)
    {
        if (false !== strpos($name, '--')) {
            $name = '@'.str_replace('--', '/', $name);
        }

        return $name;
    }

    protected function convertResultItem(array $item): array
    {
        $name = $this->convertName($item['name']);
        return [
            'name'          => "npm-asset/{$name}",
            'description'   => $item['description'] ?? null,
            'abandoned'     => false
        ];
    }

    protected function convertPackage(array $item): array
    {
        $results = [];
        $versionParser = new VersionParser();
        $name = $this->convertName($item['name']);
        foreach ($item['versions'] as $version => $data) {
            try {
                $v = $versionParser->normalize($version);
            } catch (UnexpectedValueException $e) {
                continue;
            }
            $results[] = [
                'name'               => "npm-asset/{$name}",
                'type'               => 'npm-asset-library',
                'version'            => $version,
                'version_normalized' => $v,
                'description'        => $data['description'] ?? $item['description'] ?? null,
                'keywords'           => $data['keywords'] ?? $item['keywords'] ?? [],
                'homepage'           => $item['homepage'] ?? null,
                'license'            => $item['license'] ?? null,
                'time'               => $item['time'][$version] ?? null,
                'author'             => $version['author'] ?? $item['author'] ?? [],
                'contributors'       => $data['contributors'] ?? 
                    $item['contributors'] ?? 
                    $item['maintainers'] ?? [],

                'bin'                => $data['bin'] ?? null,
                'dist' => [
                    'shasum'    => $data['dist']['shasum'] ?? '',
                    'type'      => isset($data['dist']['tarball']) ? 'tar' : '',
                    'url'       => $data['dist']['tarball'] ?? ''
                ]
            ];
        }

        return $results;
    }

    final protected function fetchFileIfLastModified(
        string $filename,
        string $cacheKey,
        string $lastModifiedTime)
    {
        try {
            $options = $this->options;

            if ($this->eventDispatcher)
            {
                $preFileDownloadEvent = new PreFileDownloadEvent(
                    PluginEvents::PRE_FILE_DOWNLOAD,
                    $this->httpDownloader, $filename,
                    'metadata',
                    ['repository' => $this]
                );

                $preFileDownloadEvent->setTransportOptions($this->options);

                $this->eventDispatcher->dispatch(
                    $preFileDownloadEvent->getName(),
                    $preFileDownloadEvent
                );
                $filename = $preFileDownloadEvent->getProcessedUrl();
                $options = $preFileDownloadEvent->getTransportOptions();
            }

            if (isset($options['http']['header'])) {
                $options['http']['header'] = (array)$options['http']['header'];
            }

            $options['http']['header'][] = 'If-Modified-Since: ' . $lastModifiedTime;
            $response = $this->httpDownloader->get($filename, $options);
            $json = (string)$response->getBody();
            if ($json === '' && $response->getStatusCode() === 304) {
                return true;
            }

            if ($this->eventDispatcher) 
            {
                $postFileDownloadEvent = new PostFileDownloadEvent(
                    PluginEvents::POST_FILE_DOWNLOAD,
                    null,
                    null,
                    $filename,
                    'metadata',
                    ['response' => $response, 'repository' => $this]
                );

                $this->eventDispatcher->dispatch(
                    $postFileDownloadEvent->getName(),
                    $postFileDownloadEvent
                );
            }

            $data = $response->decodeJson();
            HttpDownloader::outputWarnings($this->io, $this->getUrl(), $data);

            $lastModifiedDate = $response->getHeader('last-modified');
            $response->collect();
            if ($lastModifiedDate) {
                $data['last-modified'] = $lastModifiedDate;
                $json = JsonFile::encode($data, 0);
            }

            if (!$this->cache->isReadOnly()) {
                $this->cache->write($cacheKey, $json);
            }

            return $data;

        } catch (\Exception $e) {

            if ($e instanceof \LogicException) {
                throw $e;
            }

            if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                throw $e;
            }

            return true;
        }
    }


    final protected function fetchFile(
        string $filename,
        ?string $cacheKey = null,
        bool $storeLastModifiedTime = false
    )
    {
        if (null === $cacheKey) {
            $cacheKey = $filename;
            $filename = $this->getUrl() . '/' . $filename;
        }

        // url-encode $ signs in URLs as bad proxies choke on them
        if (($pos = strpos($filename, '$')) && Preg::isMatch('{^https?://}i', $filename)) {
            $filename = substr($filename, 0, $pos) . '%24' . substr($filename, $pos + 1);
        }

        $retries = 3;
        while ($retries-- > 0) {
            try {
                $options = $this->options;
                if ($this->eventDispatcher)
                {
                    $preFileDownloadEvent = new PreFileDownloadEvent(
                        PluginEvents::PRE_FILE_DOWNLOAD,
                        $this->httpDownloader,
                        $filename, 'metadata',
                        ['repository' => $this]
                    );

                    $preFileDownloadEvent->setTransportOptions($this->options);

                    $this->eventDispatcher->dispatch(
                        $preFileDownloadEvent->getName(),
                        $preFileDownloadEvent
                    );

                    $filename = $preFileDownloadEvent->getProcessedUrl();
                    $options  = $preFileDownloadEvent->getTransportOptions();
                }

                $response = $this->httpDownloader->get($filename, $options);
                $json = (string)$response->getBody();

                if ($this->eventDispatcher) 
                {
                    $postFileDownloadEvent = new PostFileDownloadEvent(
                        PluginEvents::POST_FILE_DOWNLOAD,
                        null,
                        null,
                        $filename,
                        'metadata',
                        ['response' => $response, 'repository' => $this]
                    );

                    $this->eventDispatcher->dispatch(
                        $postFileDownloadEvent->getName(),
                        $postFileDownloadEvent
                    );
                }

                $data = $response->decodeJson();
                HttpDownloader::outputWarnings($this->io, $this->getUrl(), $data);

                if ($cacheKey && !$this->cache->isReadOnly()) {
                    if ($storeLastModifiedTime) {
                        $lastModifiedDate = $response->getHeader('last-modified');
                        if ($lastModifiedDate) {
                            $data['last-modified'] = $lastModifiedDate;
                            $json = JsonFile::encode($data, 0);
                        }
                    }
                    $this->cache->write($cacheKey, $json);
                }

                $response->collect();

                break;

            } catch (\Exception $e) {
                if ($e instanceof \LogicException) {
                    throw $e;
                }

                if ($e instanceof TransportException && $e->getStatusCode() === 404) {
                    throw $e;
                }

                if ($e instanceof RepositorySecurityException) {
                    throw $e;
                }

                if ($cacheKey && ($contents = $this->cache->read($cacheKey))) {
                    $data = JsonFile::parseJson($contents, $this->cache->getRoot() . $cacheKey);

                    break;
                }

                throw $e;
            }
        }

        if (!isset($data)) {
            throw new \LogicException("ComposerRepository: Undefined \$data. Please report at https://github.com/composer/composer/issues/new.");
        }

        return $data;
    }


    protected function isVersionAcceptable(
        ?ConstraintInterface $constraint,
        string $name,
        array $versionData,
        ?array $acceptableStabilities = null,
        ?array $stabilityFlags = null
    ): bool
    {
        $versions = [$versionData['version_normalized']];

        if ($alias = $this->loader->getBranchAlias($versionData)) {
            $versions[] = $alias;
        }

        foreach ($versions as $version) 
        {
            if (null !== $acceptableStabilities && 
                null !== $stabilityFlags && 
                !StabilityFilter::isPackageAcceptable(
                    $acceptableStabilities,
                    $stabilityFlags,
                    [$name],
                    VersionParser::parseStability($version)
                )
            ) { continue; }

            if ($constraint && 
                !CompilingMatcher::match(
                    $constraint,
                    Constraint::OP_EQ,
                    $version
                )
            ) { continue; }

            return true;
        }

        return false;
    }
}
