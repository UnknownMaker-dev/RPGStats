<?php

namespace RPGStats\Modifier;

use pocketmine\utils\Config;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

/**
 * Carrega um arquivo de modificadores (races.yml, families.yml, títulos.yml).
 *
 * Os três arquivos têm o mesmo formato, então a mesma classe atende os três —
 * o que muda é só o arquivo e o rótulo usado nas mensagens.
 */
class ModifierRegistry
{
    /** @var array<string, Modifier> */
    private array $modifiers = [];

    public function __construct(
        private RPGStatsPlugin $plugin,
        private string $label,
        private string $fileName,
    ) {
        $this->load();
    }

    private function load(): void
    {
        $config = new Config($this->plugin->getDataFolder() . $this->fileName, Config::YAML);
        $logger = $this->plugin->getLogger();

        foreach ($config->getAll() as $rawId => $entry) {
            $id = strtolower((string) $rawId);

            if (!is_array($entry)) {
                $logger->warning("[{$this->fileName}] '{$id}' ignorado: o formato esperado é uma lista de chaves.");
                continue;
            }

            $attributes = [];
            foreach ((array) ($entry["atributos"] ?? $entry["attributes"] ?? []) as $stat => $value) {
                $stat = strtolower((string) $stat);

                if (!in_array($stat, PlayerDataManager::ATTRIBUTES, true)) {
                    $logger->warning("[{$this->fileName}] '{$id}' usa o atributo desconhecido '{$stat}'. Ignorado.");
                    continue;
                }

                $attributes[$stat] = (int) $value;
            }

            $multipliers = [];
            foreach ((array) ($entry["multiplicadores"] ?? $entry["multipliers"] ?? []) as $key => $value) {
                $key = strtolower((string) $key);

                if (!in_array($key, Modifier::MULTIPLIERS, true)) {
                    $logger->warning("[{$this->fileName}] '{$id}' usa o multiplicador desconhecido '{$key}'. Ignorado.");
                    continue;
                }

                $multipliers[$key] = (float) $value;
            }

            $this->modifiers[$id] = new Modifier(
                $id,
                (string) ($entry["nome"] ?? $entry["name"] ?? $id),
                (string) ($entry["descricao"] ?? $entry["description"] ?? ""),
                $attributes,
                $multipliers,
            );
        }

        $logger->info("§a[RPGStats] " . count($this->modifiers) . " {$this->label}(s) carregada(s) de {$this->fileName}.");
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function get(string $id): ?Modifier
    {
        return $this->modifiers[strtolower($id)] ?? null;
    }

    public function exists(string $id): bool
    {
        return isset($this->modifiers[strtolower($id)]);
    }

    /** @return array<string, Modifier> */
    public function all(): array
    {
        return $this->modifiers;
    }

    /** @return string[] */
    public function getIds(): array
    {
        return array_keys($this->modifiers);
    }
}
