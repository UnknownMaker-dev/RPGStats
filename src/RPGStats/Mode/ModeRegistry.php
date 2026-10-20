<?php

namespace RPGStats\Mode;

use pocketmine\utils\Config;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\Modifier\Modifier;
use RPGStats\RPGStatsPlugin;

/** Carrega o modos.yml. Mesmo desenho do ModifierRegistry, com os campos próprios do modo. */
class ModeRegistry
{
    /** @var array<string, Mode> */
    private array $modes = [];

    public function __construct(private RPGStatsPlugin $plugin)
    {
        $this->load();
    }

    private function load(): void
    {
        $config = new Config($this->plugin->getDataFolder() . "modos.yml", Config::YAML);
        $logger = $this->plugin->getLogger();

        foreach ($config->getAll() as $rawId => $entry) {
            $id = strtolower((string) $rawId);

            if (!is_array($entry)) {
                $logger->warning("[modos.yml] '{$id}' ignorado: o formato esperado é uma lista de chaves.");
                continue;
            }

            $attributes = [];
            foreach ((array) ($entry["atributos"] ?? []) as $stat => $value) {
                $stat = strtolower((string) $stat);

                if (!in_array($stat, PlayerDataManager::ATTRIBUTES, true)) {
                    $logger->warning("[modos.yml] '{$id}' usa o atributo desconhecido '{$stat}'. Ignorado.");
                    continue;
                }

                $attributes[$stat] = (int) $value;
            }

            $multipliers = [];
            foreach ((array) ($entry["multiplicadores"] ?? []) as $key => $value) {
                $key = strtolower((string) $key);

                if (!in_array($key, Modifier::MULTIPLIERS, true)) {
                    $logger->warning("[modos.yml] '{$id}' usa o multiplicador desconhecido '{$key}'. Ignorado.");
                    continue;
                }

                $multipliers[$key] = (float) $value;
            }

            $this->modes[$id] = new Mode(
                $id,
                (string) ($entry["nome"] ?? ucfirst($id)),
                (string) ($entry["descricao"] ?? ""),
                // Sem grupo declarado, cada modo é seu próprio grupo: ligar um não desliga
                // nenhum outro, que é o comportamento menos surpreendente.
                strtolower((string) ($entry["grupo"] ?? $id)),
                (string) ($entry["permissao"] ?? ""),
                max(1, (int) ($entry["nivel-requerido"] ?? 1)),
                $attributes,
                $multipliers,
                max(0, (int) ($entry["custo-mana-por-ciclo"] ?? 0)),
                $this->effects($entry["efeitos"] ?? [], $id, $logger),
                max(0, (int) ($entry["duracao-segundos"] ?? 0)),
            );
        }

        $logger->info(count($this->modes) . " modo(s) carregado(s).");
    }

    /**
     * Lê "efeitos" no formato "nome:amplificador".
     *
     * O amplificador é o do jogo: 0 é o nível I. Omitir vale 0.
     *
     * @return array<int, array{effect: string, amplifier: int}>
     */
    private function effects(mixed $raw, string $id, \AttachableLogger $logger): array
    {
        $effects = [];

        foreach ((array) $raw as $entry) {
            if (!is_string($entry) || $entry === "") {
                continue;
            }

            $parts = explode(":", $entry);
            $name = strtolower(trim($parts[0]));

            if ($name === "") {
                $logger->warning("[modos.yml] '{$id}' tem um efeito sem nome. Ignorado.");
                continue;
            }

            $effects[] = [
                "effect" => $name,
                "amplifier" => max(0, (int) ($parts[1] ?? 0)),
            ];
        }

        return $effects;
    }

    public function get(string $id): ?Mode
    {
        return $this->modes[strtolower($id)] ?? null;
    }

    public function exists(string $id): bool
    {
        return isset($this->modes[strtolower($id)]);
    }

    /** @return array<string, Mode> */
    public function all(): array
    {
        return $this->modes;
    }
}
