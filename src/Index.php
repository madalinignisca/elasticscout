<?php

namespace Rennokki\ElasticScout;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Rennokki\ElasticScout\Facades\ElasticClient;
use Rennokki\ElasticScout\Payloads\IndexPayload;
use Rennokki\ElasticScout\Payloads\TypePayload;

abstract class Index
{
    /**
     * The name.
     *
     * @var string
     */
    protected $name;

    /**
     * The settings.
     *
     * @var array
     */
    protected $settings = [];

    /**
     * The mapping.
     *
     * @var array
     */
    protected $mapping = [];

    /**
     * Initialize the index.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Check if the index is migratable.
     *
     * @return bool
     */
    public function isMigratable(): bool
    {
        return in_array(Migratable::class, class_uses_recursive($this));
    }

    /**
     * Get th name.
     *
     * @return string
     */
    public function getName(): string
    {
        $prefix = config('scout.prefix');
        $name = $this->name ?? Str::snake(str_replace('Index', '', class_basename($this)));

        return $prefix.$name;
    }

    /**
     * Get th name, resolved from the cluster.
     * If it is not migratable, it returns the name.
     *
     * @return string
     */
    public function getAliasName(): string
    {
        if (! $this->isMigratable()) {
            return $this->getName();
        }

        $aliases = ElasticClient::indices()
            ->getAlias($this->getPayload(true));

        return key($aliases);
    }

    /**
     * Get the settings.
     *
     * @return array
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Get the mapping.
     *
     * @return array
     */
    public function getMapping(): array
    {
        $mapping = $this->mapping;

        if ($this->model::usesSoftDelete() && config('scout.soft_delete', false)) {
            Arr::set($mapping, 'properties.__soft_deleted', ['type' => 'integer']);
        }

        // Set additional data for the index
        Arr::set($mapping, '_meta', [
            'model_class' => get_class($this->model),
        ]);

        return $mapping;
    }

    /**
     * Get the index payload instance.
     *
     * @param  bool  $withAlias
     * @return \Rennokki\ElasticScout\Payloads\IndexPayload
     */
    public function getPayloadInstance($withAlias = false): IndexPayload
    {
        $payload = Payload::index($this);

        if ($withAlias) {
            $payload = $payload->set('name', $this->getMigratableAlias('write'));
        }

        return $payload;
    }

    /**
     * Get the index payload for the cluster.
     *
     * @param  bool  $withAlias
     * @return array
     */
    public function getPayload($withAlias = false): array
    {
        return $this->getPayloadInstance($withAlias)->get();
    }

    /**
     * Get the model payload instance.
     *
     * @return \Rennokki\ElasticScout\Payloads\TypePayload
     */
    public function getModelPayloadInstance(): TypePayload
    {
        return Payload::type($this->model);
    }

    /**
     * Check if this index exists in the cluster.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return ElasticClient::indices()->exists($this->getPayload());
    }

    /**
     * Check if the migratable index has an alias
     * in the cluster.
     *
     * @return bool
     */
    public function hasAlias(): bool
    {
        if (! $this->isMigratable()) {
            return false;
        }

        return ElasticClient::indices()->existsAlias($this->getPayload(true));
    }

    /**
     * Create this index in the Elasticsearch cluster.
     * In case it is migratable, also create alias.
     *
     * @return bool
     */
    public function create(): bool
    {
        if ($this->exists()) {
            return $this->sync();
        }

        // Set settings of the index right at creation.
        $payload = $this->getPayloadInstance()
            ->setIfNotEmpty('body.settings', $this->getSettings())
            ->get();

        ElasticClient::indices()
            ->create($payload);

        // If the index is migratable, it means it has to have an alias
        // in case of a migration might occur in the near future.
        $this->createAlias();

        return true;
    }

    /**
     * Create alias if this index is migratable.
     *
     * @return bool
     */
    public function createAlias(): bool
    {
        if (! $this->isMigratable()) {
            return false;
        }

        if (! $this->exists()) {
            $this->sync();
        }

        ElasticClient::indices()
            ->putAlias($this->getPayload(true));

        return true;
    }

    /**
     * Delete the index from the cluster.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (! $this->exists() && ! $this->hasAlias()) {
            return true;
        }

        $payload = Payload::raw()
            ->set('index', $this->getAliasName())
            ->get();

        ElasticClient::indices()->delete($payload);

        return true;
    }

    /**
     * Sync the index to the cluster.
     *
     * @return bool
     */
    public function sync(): bool
    {
        if (! $this->exists()) {
            $this->create();
        }

        // Sync the Settings
        $this->syncSettings();

        // Sync the Mapping
        $this->syncMapping();

        // If the index is migratable, also
        // sync its alias to the cluster.
        $this->syncAlias();

        return true;
    }

    /**
     * Sync the alias index to the cluster.
     *
     * @return bool
     */
    public function syncAlias(): bool
    {
        if (! $this->hasAlias()) {
            return $this->createAlias();
        }

        if (! $this->isMigratable()) {
            return false;
        }

        ElasticClient::indices()->putAlias($this->getPayload(true));

        return true;
    }

    /**
     * Sync the mapping to the cluster.
     *
     * @return bool
     */
    public function syncMapping(): bool
    {
        if (! $this->getMapping()) {
            return false;
        }

        if (! $this->exists()) {
            $this->sync();
        }

        $payload = $this->getModelPayloadInstance()
            ->set("body.{$this->model->searchableAs()}", $this->getMapping())
            ->set('include_type_name', 'true');

        if ($this->isMigratable()) {
            $payload = $payload->withAlias('write');
        }

        ElasticClient::indices()
            ->putMapping($payload->get());

        return true;
    }

    /**
     * Sync the settings to the cluster.
     *
     * @return bool
     */
    public function syncSettings(): bool
    {
        if (! $this->getSettings()) {
            return false;
        }

        if (! $this->exists()) {
            $this->sync();
        }

        $payload = $this->getPayloadInstance()
            ->set('body.settings', $this->getSettings())
            ->get();

        try {
            ElasticClient::indices()->close($this->getPayload());

            ElasticClient::indices()->putSettings($payload);

            ElasticClient::indices()->open($this->getPayload());
        } catch (Exception $e) {
            ElasticClient::indices()->open($this->getPayload());

            return false;
        }

        return true;
    }

    /**
     * Get the raw index from the API.
     *
     * @return array
     */
    public function getRaw(): array
    {
        return ElasticClient::indices()->get($this->getPayload())[$this->getName()] ?? [];
    }

    /**
     * Get the raw mapping from the API.
     *
     * @return array
     */
    public function getRawMapping(): array
    {
        return ElasticClient::indices()->getMapping($this->getPayload())[$this->getName()]['mappings'] ?? [];
    }

    /**
     * Get raw settings from the API.
     *
     * @return array
     */
    public function getRawSettings(): array
    {
        return ElasticClient::indices()->getSettings($this->getPayload())[$this->getName()]['settings']['index'] ?? [];
    }
}
