<?php

namespace RPGStats\Manager;

use pocketmine\player\Player;
use pocketmine\utils\Config;
use RPGStats\Form\UiPreset;
use RPGStats\Modifier\Modifier;
use RPGStats\RPGStatsPlugin;

/**
 * Guarda os dados dos jogadores em memória e só escreve no players.yml quando algo mudou.
 *
 * O disco é tocado em três momentos: quando o jogador sai, no auto-save periódico e no
 * onDisable. Fora isso tudo acontece no cache, então gastar Ki a cada golpe não gera I/O.
 */
class PlayerDataManager
{
    /**
     * Atributos que recebem pontos e bônus de classe, rank e título.
     *
     * Aqui o atributo É o status: "hp" sobe a vida máxima direto, sem passar por um
     * atributo intermediário como CON. É o que faz a janela de status bater exatamente com
     * o que o jogador comprou, sem uma tabela de conversão no meio.
     *
     * "mana" é o TAMANHO do poço de mana; "mp" (em EDITABLE) é quanto resta dele agora.
     */
    public const ATTRIBUTES = ["hp", "damage", "defense", "speed", "mana", "atk_speed"];

    /** Tudo que o /stats aceita manipular (atributos + recursos). */
    public const EDITABLE = ["hp", "damage", "defense", "speed", "mana", "atk_speed", "tp", "mp"];

    /**
     * Chaves da progressão.
     *
     * Ficam fora do EDITABLE de propósito: mexer no XP por /stats furaria o level-up,
     * e mexer nos pontos daria atributo de graça. Quem altera isso é /level e /points.
     */
    public const PROGRESSION = ["xp", "level", "points"];

    private Config $data;

    private float $defensePerPoint;
    private float $defenseScale;
    private float $maxReduction;

    private int $baseHealth;
    private float $healthPerPoint;
    private int $baseMana;
    private float $manaPerPoint;
    private float $damagePerPoint;
    private float $baseSpeed;
    private float $speedPerPoint;
    private float $speedCap;
    private float $attackSpeedPerPoint;
    private float $maxAttackSpeed;

    /** @var array<string, \RPGStats\Stat\StatProvider> */
    private array $providers = [];

    private int $maxLevel;
    private int $pointsPerLevel;
    private int $xpBase;
    private float $xpExponent;

    private int $trainingCostBase;
    private float $trainingCostExponent;

    /** @var array<string, array<string, mixed>> nome minúsculo => stats */
    private array $cache = [];

    /** @var array<string, bool> nome minúsculo => alterado desde a última gravação */
    private array $dirty = [];

    /** @var array<string, string> nome minúsculo => chave real no players.yml */
    private array $keyMap = [];

    public function __construct(private RPGStatsPlugin $plugin)
    {
        $this->data = new Config(
            $plugin->getDataFolder() . "players.yml",
            Config::YAML,
        );

        foreach (array_keys($this->data->getAll()) as $key) {
            $this->keyMap[strtolower((string) $key)] = (string) $key;
        }

        $config = $plugin->getConfig();

        $this->defensePerPoint = (float) $config->getNested("combat.defesa-por-ponto", 1.0);
        $this->defenseScale = max(0.0, (float) $config->getNested("combat.escala-defesa", 1.2));
        $this->maxReduction = min(0.95, max(0.0, (float) $config->getNested("combat.reducao-maxima", 0.8)));

        $this->baseHealth = max(1, (int) $config->getNested("atributos.vida.base", 20));
        $this->healthPerPoint = (float) $config->getNested("atributos.vida.por-ponto", 2.0);
        $this->baseMana = max(0, (int) $config->getNested("atributos.mana.base", 20));
        $this->manaPerPoint = (float) $config->getNested("atributos.mana.por-ponto", 10.0);
        $this->damagePerPoint = (float) $config->getNested("atributos.dano.por-ponto", 0.5);
        $this->baseSpeed = (float) $config->getNested("atributos.velocidade.base", 0.1);
        $this->speedPerPoint = (float) $config->getNested("atributos.velocidade.por-ponto", 0.005);
        // Trava dura do jogo: acima disso o cliente engasga e a movimentação fica intratável.
        $this->speedCap = min(0.5, max(0.01, (float) $config->getNested("atributos.velocidade.teto", 0.4)));
        $this->attackSpeedPerPoint = (float) $config->getNested("atributos.velocidade-ataque.por-ponto", 0.02);
        $this->maxAttackSpeed = min(0.9, max(0.0, (float) $config->getNested("atributos.velocidade-ataque.reducao-maxima", 0.6)));

        $this->maxLevel = max(1, (int) $config->getNested("progression.max-level", 100));
        $this->pointsPerLevel = max(0, (int) $config->getNested("progression.points-per-level", 3));
        $this->xpBase = max(1, (int) $config->getNested("progression.xp.base", 25));
        $this->xpExponent = max(0.1, (float) $config->getNested("progression.xp.exponent", 1.5));

        $this->trainingCostBase = max(1, (int) $config->getNested("training.cost.base", 10));
        $this->trainingCostExponent = max(0.1, (float) $config->getNested("training.cost.exponent", 1.0));
    }

    // ───────────────────────────── ciclo de vida ─────────────────────────────

    /** Puxa o jogador do disco para a memória. Registra se for a primeira vez. */
    public function load(string $name): void
    {
        $key = strtolower($name);

        if (isset($this->cache[$key])) {
            return;
        }

        $stored = isset($this->keyMap[$key]) ? $this->data->get($this->keyMap[$key]) : null;

        if (!is_array($stored)) {
            $this->keyMap[$key] = $name;
            $this->cache[$key] = $this->getDefaults();
            $this->dirty[$key] = true;
            return;
        }

        $normalized = $this->normalize($stored);
        $this->cache[$key] = $normalized;

        // Dados de uma versão antiga do plugin: grava o formato novo na próxima descarga.
        if ($normalized !== $stored) {
            $this->dirty[$key] = true;
        }
    }

    /** Grava o jogador e tira da memória (usado quando ele sai). */
    public function unload(string $name): void
    {
        $key = strtolower($name);

        if (!isset($this->cache[$key])) {
            return;
        }

        if (isset($this->dirty[$key])) {
            $this->data->set($this->keyMap[$key], $this->cache[$key]);
            unset($this->dirty[$key]);
            $this->data->save();
        }

        unset($this->cache[$key]);
    }

    /** Descarrega no disco tudo que mudou. Não escreve nada se nada mudou. */
    public function flush(): void
    {
        $changed = false;

        foreach (array_keys($this->dirty) as $key) {
            if (!isset($this->cache[$key])) {
                continue;
            }

            $this->data->set($this->keyMap[$key], $this->cache[$key]);
            $changed = true;
        }

        $this->dirty = [];

        if ($changed) {
            $this->data->save();
        }

        $this->forgetOffline();
    }

    /** Tira do cache quem já saiu, para a memória não crescer sem limite. */
    private function forgetOffline(): void
    {
        foreach (array_keys($this->cache) as $key) {
            if (isset($this->dirty[$key])) {
                continue;
            }

            if ($this->plugin->getServer()->getPlayerExact($key) !== null) {
                continue;
            }

            unset($this->cache[$key]);
        }
    }

    // ───────────────────────────── leitura ─────────────────────────────

    public function isRegistered(string $name): bool
    {
        $key = strtolower($name);

        return isset($this->cache[$key]) || isset($this->keyMap[$key]);
    }

    /**
     * Stats do jogador, ou null se ele nunca entrou no servidor.
     *
     * Devolver null é de propósito: evita que um erro de digitação em /stats crie
     * uma entrada de lixo no players.yml.
     *
     * @return array<string, mixed>|null
     */
    public function getStats(string $name): ?array
    {
        $key = strtolower($name);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (!isset($this->keyMap[$key])) {
            return null;
        }

        $stored = $this->data->get($this->keyMap[$key]);

        if (!is_array($stored)) {
            return null;
        }

        $normalized = $this->normalize($stored);
        $this->cache[$key] = $normalized;

        if ($normalized !== $stored) {
            $this->dirty[$key] = true;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $stats */
    private function setStats(string $name, array $stats): void
    {
        $key = strtolower($name);

        $this->cache[$key] = $stats;
        $this->dirty[$key] = true;

        if (!isset($this->keyMap[$key])) {
            $this->keyMap[$key] = $name;
        }
    }

    /** @return array<string, mixed> */
    public function getDefaults(): array
    {
        $config = $this->plugin->getConfig();

        return [
            "hp" => 1,
            "damage" => 1,
            "defense" => 1,
            "speed" => 1,
            "mana" => 1,
            "atk_speed" => 1,
            "tp" => 5,
            "mp" => 20,
            "xp" => 0,
            "level" => 1,
            "points" => 0,
            "class" => strtolower((string) $config->get("classe-padrao", "")),
            "rank" => strtolower((string) $config->get("rank-padrao", "")),
            // Títulos conquistados: todos dão bônus. "title" é só qual deles aparece no nome.
            "titles" => [],
            "title" => "",
            // Habilidades upáveis: id => nível. Nível 0 significa não desbloqueada.
            "skills" => [],
            "ui" => strtolower((string) $config->getNested("ui.default-preset", UiPreset::DEFAULT_ID)),
        ];
    }

    /**
     * Completa chaves que faltam e arruma tipos.
     *
     * É isso que migra os dados de quem já jogava antes do Chakra, das raças e dos títulos.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function normalize(array $stats): array
    {
        $defaults = $this->getDefaults();
        $normalized = [];

        foreach (self::EDITABLE as $stat) {
            $normalized[$stat] = isset($stats[$stat])
                ? max(0, (int) $stats[$stat])
                : $defaults[$stat];
        }

        // Quem já jogava antes da progressão entra no nível 1, sem XP e sem pontos.
        $normalized["xp"] = max(0, (int) ($stats["xp"] ?? $defaults["xp"]));
        $normalized["level"] = max(1, (int) ($stats["level"] ?? $defaults["level"]));
        $normalized["points"] = max(0, (int) ($stats["points"] ?? $defaults["points"]));

        $normalized["class"] = isset($stats["class"])
            ? strtolower((string) $stats["class"])
            : $defaults["class"];

        $normalized["rank"] = isset($stats["rank"])
            ? strtolower((string) $stats["rank"])
            : $defaults["rank"];

        // Estilo antigo ou removido? Vira o tema equivalente, ou o padrão.
        $normalized["ui"] = UiPreset::resolve((string) ($stats["ui"] ?? $defaults["ui"]));

        $titles = [];
        foreach ((array) ($stats["titles"] ?? []) as $titleId) {
            $titleId = strtolower((string) $titleId);

            if ($titleId !== "" && !in_array($titleId, $titles, true)) {
                $titles[] = $titleId;
            }
        }
        $normalized["titles"] = $titles;

        // Título em exibição só vale se o jogador ainda o tiver: perder o título tem de
        // tirá-lo do nome também, senão o nametag mente.
        $title = strtolower((string) ($stats["title"] ?? $defaults["title"]));
        $normalized["title"] = in_array($title, $titles, true) ? $title : "";

        $skills = [];
        foreach ((array) ($stats["skills"] ?? []) as $skillId => $level) {
            $skillId = strtolower((string) $skillId);

            if ($skillId !== "" && is_numeric($level)) {
                $skills[$skillId] = max(0, (int) $level);
            }
        }
        $normalized["skills"] = $skills;

        return $normalized;
    }

    // ───────────────────────── classe, rank e títulos ─────────────────────────

    /**
     * Classe + família + títulos do jogador, na ordem em que os bônus são aplicados.
     *
     * @return Modifier[]
     */
    public function getModifiers(string $name): array
    {
        $stats = $this->getStats($name);

        if ($stats === null) {
            return [];
        }

        $modifiers = [];

        $playerClass = $this->plugin->getClassRegistry()->get((string) $stats["class"]);
        if ($playerClass !== null) {
            $modifiers[] = $playerClass;
        }

        $rank = $this->plugin->getRankRegistry()->get((string) $stats["rank"]);
        if ($rank !== null) {
            $modifiers[] = $rank;
        }

        foreach ((array) $stats["titles"] as $titleId) {
            $title = $this->plugin->getTitleRegistry()->get((string) $titleId);
            if ($title !== null) {
                $modifiers[] = $title;
            }
        }

        return $modifiers;
    }

    public function getPlayerClass(string $name): ?Modifier
    {
        return $this->plugin->getClassRegistry()->get((string) ($this->getStats($name)["class"] ?? ""));
    }

    public function getRank(string $name): ?Modifier
    {
        return $this->plugin->getRankRegistry()->get((string) ($this->getStats($name)["rank"] ?? ""));
    }

    /** @return Modifier[] */
    public function getTitles(string $name): array
    {
        $titles = [];

        foreach ((array) ($this->getStats($name)["titles"] ?? []) as $titleId) {
            $title = $this->plugin->getTitleRegistry()->get((string) $titleId);
            if ($title !== null) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    public function setPlayerClass(string $name, string $classId): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null) {
            return false;
        }

        $classId = strtolower($classId);

        if ($classId !== "" && !$this->plugin->getClassRegistry()->exists($classId)) {
            return false;
        }

        $stats["class"] = $classId;
        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    public function setRank(string $name, string $rankId): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null) {
            return false;
        }

        $rankId = strtolower($rankId);

        if ($rankId !== "" && !$this->plugin->getRankRegistry()->exists($rankId)) {
            return false;
        }

        $stats["rank"] = $rankId;
        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    public function addTitle(string $name, string $titleId): bool
    {
        $stats = $this->getStats($name);
        $titleId = strtolower($titleId);

        if ($stats === null || !$this->plugin->getTitleRegistry()->exists($titleId)) {
            return false;
        }

        if (in_array($titleId, (array) $stats["titles"], true)) {
            return false;
        }

        $stats["titles"][] = $titleId;
        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    public function removeTitle(string $name, string $titleId): bool
    {
        $stats = $this->getStats($name);
        $titleId = strtolower($titleId);

        if ($stats === null) {
            return false;
        }

        $titles = (array) $stats["titles"];
        $index = array_search($titleId, $titles, true);

        if ($index === false) {
            return false;
        }

        unset($titles[$index]);
        $stats["titles"] = array_values($titles);
        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    public function clearTitles(string $name): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null) {
            return false;
        }

        $stats["titles"] = [];
        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    // ───────────────────────────── atributos ─────────────────────────────

    /** Valor cru, sem bônus de raça/família/título. */
    public function getBaseStat(string $name, string $stat): int
    {
        return (int) ($this->getStats($name)[$stat] ?? 0);
    }

    /**
     * Registra uma fonte externa de bônus (armadura, item na mão, buff).
     *
     * O id serve para o próprio plugin se desregistrar no onDisable — sem isso, um reload
     * deixaria o provider morto na lista e todo cálculo de atributo estouraria.
     */
    public function registerProvider(string $id, \RPGStats\Stat\StatProvider $provider): void
    {
        $this->providers[strtolower($id)] = $provider;
    }

    public function unregisterProvider(string $id): void
    {
        unset($this->providers[strtolower($id)]);
    }

    /** Valor com os bônus de classe, rank, títulos e providers externos já somados. */
    public function getStat(string $name, string $stat): int
    {
        $value = $this->getBaseStat($name, $stat);

        if (!in_array($stat, self::ATTRIBUTES, true)) {
            return $value;
        }

        foreach ($this->getModifiers($name) as $modifier) {
            $value += $modifier->getAttributeBonus($stat);
        }

        foreach ($this->providers as $provider) {
            // Um provider não pode inventar atributo: só somamos o que já existe na ficha.
            $value += (int) ($provider->getAttributeBonuses($name)[$stat] ?? 0);
        }

        return max(0, $value);
    }

    /**
     * Produto dos multiplicadores de classe, rank, títulos e providers para uma chave.
     *
     * Fontes diferentes compõem por multiplicação: +50% de classe com +20% de título dá
     * 1.5 * 1.2 = 1.8, não 1.7. Somar deixaria fácil chegar a zero ou negativo empilhando
     * penalidades.
     */
    public function getMultiplier(string $name, string $key): float
    {
        $value = 1.0;

        foreach ($this->getModifiers($name) as $modifier) {
            $value *= $modifier->getMultiplier($key);
        }

        foreach ($this->providers as $provider) {
            $value *= (float) ($provider->getMultipliers($name)[$key] ?? 1.0);
        }

        return $value;
    }

    public function addStat(string $name, string $statName, int $amount): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null || !in_array($statName, self::EDITABLE, true)) {
            return false;
        }

        $stats[$statName] += $amount;
        $this->setStats($name, $stats);
        $this->refreshIfDerived($name, $statName);

        return true;
    }

    public function removeStat(string $name, string $statName, int $amount): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null || !in_array($statName, self::EDITABLE, true)) {
            return false;
        }

        // Definindo pra 0 caso não tenha valor o suficiente pra retirar.
        $stats[$statName] = max(0, $stats[$statName] - $amount);
        $this->setStats($name, $stats);
        $this->refreshIfDerived($name, $statName);

        return true;
    }

    public function setStat(string $name, string $statName, int $amount): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null || !in_array($statName, self::EDITABLE, true) || $amount < 0) {
            return false;
        }

        $stats[$statName] = $amount;
        $this->setStats($name, $stats);
        $this->refreshIfDerived($name, $statName);

        return true;
    }

    /** Recalcular vida/velocidade só faz sentido quando um atributo muda; Ki e TP não mexem neles. */
    private function refreshIfDerived(string $name, string $statName): void
    {
        if (in_array($statName, self::ATTRIBUTES, true)) {
            $this->refresh($name);
        }
    }

    // ───────────────────────── valores derivados ─────────────────────────

    /**
     * Vida máxima: base do config + o que o atributo HP acrescenta.
     *
     * O primeiro ponto é o piso, por isso o `- 1`: um jogador recém-criado tem exatamente
     * a vida base, não a base mais um degrau.
     */
    public function getMaxHealth(string $name): int
    {
        $base = $this->baseHealth + max(0, $this->getStat($name, "hp") - 1) * $this->healthPerPoint;

        return max(1, (int) round($base * $this->getMultiplier($name, "health")));
    }

    /** Poço de mana: base do config + o que o atributo MANA acrescenta. */
    public function getMaxMana(string $name): int
    {
        $base = $this->baseMana + max(0, $this->getStat($name, "mana") - 1) * $this->manaPerPoint;

        return max(1, (int) round($base * $this->getMultiplier($name, "mana_max")));
    }

    /**
     * Dano extra somado ao golpe.
     *
     * É somado ao dano da arma, não multiplicado por ele: o multiplicador "damage" incide
     * só sobre este bônus, senão uma espada boa viraria o dobro de tudo.
     */
    public function getDamageBonus(string $name): float
    {
        $bonus = max(0, $this->getStat($name, "damage") - 1) * $this->damagePerPoint;

        return $bonus * $this->getMultiplier($name, "damage");
    }

    /** Velocidade de caminhada, com teto duro para o cliente não engasgar. */
    public function getMovementSpeed(string $name): float
    {
        $speed = $this->baseSpeed + max(0, $this->getStat($name, "speed") - 1) * $this->speedPerPoint;

        return min($speed * $this->getMultiplier($name, "speed"), $this->speedCap);
    }

    /**
     * Fração do tempo de recarga entre golpes que o atributo ATK_SPEED corta.
     *
     * Devolve 0..reducao-maxima. O teto existe porque zerar a recarga transforma qualquer
     * arma em dano contínuo — o cliente manda um ataque por tick enquanto o botão estiver
     * pressionado.
     */
    public function getAttackSpeedBonus(string $name): float
    {
        $bonus = max(0, $this->getStat($name, "atk_speed") - 1) * $this->attackSpeedPerPoint;

        return min($bonus * $this->getMultiplier($name, "atk_speed"), $this->maxAttackSpeed);
    }

    // ───────────────────────── estilo da ficha ─────────────────────────

    public function getUiPreset(string $name): UiPreset
    {
        return UiPreset::get((string) ($this->getStats($name)["ui"] ?? UiPreset::DEFAULT_ID));
    }

    public function setUiPreset(string $name, string $presetId): bool
    {
        $stats = $this->getStats($name);
        $presetId = strtolower($presetId);

        if ($stats === null || !UiPreset::exists($presetId)) {
            return false;
        }

        $stats["ui"] = $presetId;
        $this->setStats($name, $stats);

        return true;
    }

    // ───────────────────────── defesa ─────────────────────────

    /** Defesa do jogador: vem do atributo DEFESA, com os multiplicadores aplicados. */
    public function getDefense(string $name): float
    {
        $defense = max(0, $this->getStat($name, "defense") - 1) * $this->defensePerPoint;

        return max(0.0, $defense * $this->getMultiplier($name, "defense"));
    }

    /**
     * Fração do dano que a defesa absorve, de 0 até o teto do config.
     *
     * Mitigação percentual com retorno decrescente: cada ponto de MND vale um pouco
     * menos que o anterior, então nenhum acúmulo de defesa chega a zerar o dano.
     */
    public function getDamageReduction(string $name): float
    {
        $defense = $this->getDefense($name);

        if ($defense <= 0) {
            return 0.0;
        }

        $reduction = 1 - (100 / (100 + $defense * $this->defenseScale));

        return min($reduction, $this->maxReduction);
    }

    // ───────────────────────── XP, nível e pontos ─────────────────────────

    public function getMaxLevel(): int
    {
        return $this->maxLevel;
    }

    public function getPointsPerLevel(): int
    {
        return $this->pointsPerLevel;
    }

    public function getLevel(string $name): int
    {
        return max(1, (int) ($this->getStats($name)["level"] ?? 1));
    }

    public function getXp(string $name): int
    {
        return (int) ($this->getStats($name)["xp"] ?? 0);
    }

    public function getPoints(string $name): int
    {
        return (int) ($this->getStats($name)["points"] ?? 0);
    }

    public function isMaxLevel(string $name): bool
    {
        return $this->getLevel($name) >= $this->maxLevel;
    }

    /** XP necessário para sair do nível informado. */
    public function getXpForLevel(int $level): int
    {
        return max(1, (int) round($this->xpBase * max(1, $level) ** $this->xpExponent));
    }

    /** Quanto falta de XP para o jogador subir de nível. */
    public function getXpToNextLevel(string $name): int
    {
        if ($this->isMaxLevel($name)) {
            return 0;
        }

        return max(0, $this->getXpForLevel($this->getLevel($name)) - $this->getXp($name));
    }

    /** XP que o jogador realmente recebe de uma recompensa, já com o multiplicador. */
    public function getXpGain(string $name, int $amount): int
    {
        return (int) round($amount * $this->getMultiplier($name, "xp_gain"));
    }

    /**
     * Soma XP e sobe de nível quantas vezes couber, dando os pontos de cada nível.
     *
     * @return int quantos níveis o jogador subiu (0 se não subiu nenhum)
     */
    public function addXp(string $name, int $amount): int
    {
        $stats = $this->getStats($name);

        if ($stats === null || $amount <= 0) {
            return 0;
        }

        $level = max(1, (int) $stats["level"]);

        // No nível máximo o XP não tem para onde ir; nem entra na conta.
        if ($level >= $this->maxLevel) {
            return 0;
        }

        $amount = $this->getXpGain($name, $amount);

        if ($amount <= 0) {
            return 0;
        }

        $xp = max(0, (int) $stats["xp"]) + $amount;
        $gained = 0;

        while ($level < $this->maxLevel && $xp >= ($needed = $this->getXpForLevel($level))) {
            $xp -= $needed;
            $level++;
            $gained++;
        }

        if ($level >= $this->maxLevel) {
            $xp = 0;
        }

        $stats["xp"] = $xp;
        $stats["level"] = $level;
        $stats["points"] = max(0, (int) $stats["points"]) + $gained * $this->pointsPerLevel;

        $this->setStats($name, $stats);

        return $gained;
    }

    /**
     * Define o XP acumulado dentro do nível atual.
     *
     * Trava um abaixo do necessário para subir: quem quiser fazer o jogador subir de
     * nível usa addXp, que é o único caminho que entrega os pontos.
     */
    public function setXp(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null || $amount < 0) {
            return false;
        }

        $ceiling = max(0, $this->getXpForLevel($this->getLevel($name)) - 1);

        return $this->setProgression($name, "xp", min($amount, $ceiling));
    }

    /** Tira XP do nível atual. Não faz o jogador descer de nível. */
    public function removeXp(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null || $amount < 0) {
            return false;
        }

        return $this->setProgression($name, "xp", max(0, $this->getXp($name) - $amount));
    }

    public function setPoints(string $name, int $amount): bool
    {
        return $this->setProgression($name, "points", $amount);
    }

    public function addPoints(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null || $amount <= 0) {
            return false;
        }

        return $this->setProgression($name, "points", $this->getPoints($name) + $amount);
    }

    public function removePoints(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null || $amount <= 0) {
            return false;
        }

        return $this->setProgression($name, "points", max(0, $this->getPoints($name) - $amount));
    }

    /**
     * Troca pontos por atributo.
     *
     * Só atributos entram: gastar ponto em TP ou Ki seria comprar recurso, não evolução.
     */
    public function spendPoints(string $name, string $stat, int $amount): bool
    {
        $stats = $this->getStats($name);
        $stat = strtolower($stat);

        if ($stats === null || $amount <= 0 || !in_array($stat, self::ATTRIBUTES, true)) {
            return false;
        }

        if ((int) $stats["points"] < $amount) {
            return false;
        }

        $stats["points"] -= $amount;
        $stats[$stat] += $amount;

        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    private function setProgression(string $name, string $key, int $value): bool
    {
        $stats = $this->getStats($name);

        if ($stats === null || !in_array($key, self::PROGRESSION, true) || $value < 0) {
            return false;
        }

        $stats[$key] = $value;
        $this->setStats($name, $stats);

        return true;
    }

    // ───────────────────────── TP (pontos de treino) ─────────────────────────

    /**
     * O TP é ganho batendo e trocado por atributo em /training spend.
     *
     * Continua dentro de EDITABLE, como o Mana: /stats set fulano tp 100 funciona.
     * É recurso de op mesmo — o que não pode virar atalho é o ponto de nível.
     */
    public function getTp(string $name): int
    {
        return (int) ($this->getStats($name)["tp"] ?? 0);
    }

    public function addTp(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null || $amount <= 0) {
            return false;
        }

        return $this->setStat($name, "tp", $this->getTp($name) + $amount);
    }

    public function removeTp(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null || $amount <= 0) {
            return false;
        }

        return $this->setStat($name, "tp", max(0, $this->getTp($name) - $amount));
    }

    public function setTp(string $name, int $amount): bool
    {
        return $this->setStat($name, "tp", $amount);
    }

    /**
     * Quanto custa sair do valor informado para o próximo ponto.
     *
     * O custo sobe junto com o atributo: sem isso, quem farma golpe sem parar
     * chegaria a valores absurdos, já que TP não tem teto.
     */
    public function getTrainingCost(int $currentValue): int
    {
        return max(1, (int) round($this->trainingCostBase * max(1, $currentValue) ** $this->trainingCostExponent));
    }

    /**
     * Custo total de subir vários pontos de uma vez.
     *
     * Cada ponto é cobrado pelo preço da sua própria faixa, então comprar 5 de
     * uma vez custa o mesmo que comprar 5 vezes um.
     */
    public function getTrainingCostFor(string $name, string $stat, int $amount): int
    {
        $base = $this->getBaseStat($name, $stat);
        $total = 0;

        for ($i = 0; $i < $amount; $i++) {
            $total += $this->getTrainingCost($base + $i);
        }

        return $total;
    }

    /** Troca TP por atributo. Só atributos: TP não compra Ki nem mais TP. */
    public function spendTraining(string $name, string $stat, int $amount): bool
    {
        $stats = $this->getStats($name);
        $stat = strtolower($stat);

        if ($stats === null || $amount <= 0 || !in_array($stat, self::ATTRIBUTES, true)) {
            return false;
        }

        $cost = $this->getTrainingCostFor($name, $stat, $amount);

        if ((int) $stats["tp"] < $cost) {
            return false;
        }

        $stats["tp"] -= $cost;
        $stats[$stat] += $amount;

        $this->setStats($name, $stats);
        $this->refresh($name);

        return true;
    }

    // ───────────────────────────── Ki ─────────────────────────────

    public function getMana(string $name): int
    {
        return (int) ($this->getStats($name)["mp"] ?? 0);
    }

    public function setMana(string $name, int $amount): bool
    {
        if ($amount < 0) {
            return false;
        }

        return $this->setStat($name, "mp", min($amount, $this->getMaxMana($name)));
    }

    public function addMana(string $name, int $amount): bool
    {
        if ($this->getStats($name) === null) {
            return false;
        }

        return $this->setStat($name, "mp", min($this->getMana($name) + $amount, $this->getMaxMana($name)));
    }

    public function removeMana(string $name, int $amount): bool
    {
        if (!$this->hasEnoughMana($name, $amount)) {
            return false;
        }

        return $this->setStat($name, "mp", $this->getMana($name) - $amount);
    }

    public function hasEnoughMana(string $name, int $cost): bool
    {
        return $this->getStats($name) !== null && $this->getMana($name) >= $cost;
    }

    /** Corta o Ki atual se o máximo caiu (perdeu CHAKRA, raça ou título). */
    public function clampMana(string $name): void
    {
        $stats = $this->getStats($name);

        if ($stats === null) {
            return;
        }

        $maxKi = $this->getMaxMana($name);

        if ($stats["mp"] > $maxKi) {
            $stats["mp"] = $maxKi;
            $this->setStats($name, $stats);
        }
    }

    // ───────────────────────── título em exibição ─────────────────────────

    /**
     * O título que aparece junto do nome. String vazia = nenhum.
     *
     * Ter o título e exibir o título são coisas separadas: todo título conquistado dá
     * bônus, mas só um de cada vez vai para o nametag.
     */
    public function getEquippedTitle(string $name): string
    {
        return (string) ($this->getStats($name)["title"] ?? "");
    }

    /** Passe "" para tirar o título do nome. Só aceita título que o jogador tenha. */
    public function setEquippedTitle(string $name, string $titleId): bool
    {
        $stats = $this->getStats($name);
        $titleId = strtolower($titleId);

        if ($stats === null) {
            return false;
        }

        if ($titleId !== "" && !in_array($titleId, (array) $stats["titles"], true)) {
            return false;
        }

        $stats["title"] = $titleId;
        $this->setStats($name, $stats);

        return true;
    }

    // ───────────────────────── habilidades upáveis ─────────────────────────

    /**
     * Níveis das habilidades do jogador: id => nível.
     *
     * Habilidade que nunca foi comprada não aparece no mapa. Nível 0 e ausência são a
     * mesma coisa para quem lê — `getSkillLevel()` devolve 0 nos dois casos.
     *
     * @return array<string, int>
     */
    public function getSkills(string $name): array
    {
        $skills = $this->getStats($name)["skills"] ?? [];

        return is_array($skills) ? $skills : [];
    }

    public function getSkillLevel(string $name, string $skillId): int
    {
        return max(0, (int) ($this->getSkills($name)[strtolower($skillId)] ?? 0));
    }

    public function hasSkill(string $name, string $skillId): bool
    {
        return $this->getSkillLevel($name, $skillId) > 0;
    }

    /** Nível 0 apaga a habilidade do arquivo em vez de guardar um zero. */
    public function setSkillLevel(string $name, string $skillId, int $level): bool
    {
        $stats = $this->getStats($name);
        $skillId = strtolower($skillId);

        if ($stats === null || $level < 0) {
            return false;
        }

        $skills = is_array($stats["skills"] ?? null) ? $stats["skills"] : [];

        if ($level === 0) {
            unset($skills[$skillId]);
        } else {
            $skills[$skillId] = $level;
        }

        $stats["skills"] = $skills;
        $this->setStats($name, $stats);

        return true;
    }

    // ───────────────────────── aplicação no jogador ─────────────────────────

    /** Reaplica vida máxima, velocidade e teto de Ki. Chamado sempre que algo muda. */
    public function refresh(string $name): void
    {
        $this->clampMana($name);

        $player = $this->plugin->getServer()->getPlayerExact($name);

        if ($player !== null) {
            $this->applyAttributes($player);
        }
    }

    public function applyAttributes(Player $player): void
    {
        $name = $player->getName();

        if ($this->getStats($name) === null) {
            return;
        }

        $maxHealth = $this->getMaxHealth($name);
        $player->setMaxHealth($maxHealth);

        // Só corta para baixo: curar de graça a cada alteração seria explorável com relog.
        if ($player->getHealth() > $maxHealth) {
            $player->setHealth($maxHealth);
        }

        $player->setMovementSpeed($this->getMovementSpeed($name));
    }
}
