<?php

namespace RPGStats\Manager;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\player\Player;
use RPGStats\Mode\Mode;
use RPGStats\RPGStatsPlugin;
use RPGStats\Stat\StatProvider;

/**
 * Liga, desliga e cobra os modos ativos.
 *
 * Implementa StatProvider e se registra no PlayerDataManager: com isso, o bônus de um modo
 * entra em `getStat()` como qualquer outro, sem que o cálculo de atributo precise saber
 * que modos existem.
 *
 * **Nada aqui vai para o disco.** Modo é estado de sessão: quem sai transformado volta
 * normal. Persistir traria o problema de reentrar com um modo ligado sem mana para pagá-lo,
 * e de bônus vivos para um jogador offline.
 */
class ModeManager implements StatProvider
{
    /** @var array<string, array<string, int>> nome minúsculo => id do modo => tick em que expira (0 = sem prazo) */
    private array $active = [];

    public function __construct(private RPGStatsPlugin $plugin) {}

    // ───────────────────────── StatProvider ─────────────────────────

    /**
     * @return array<string, int>
     */
    public function getAttributeBonuses(string $playerName): array
    {
        $bonuses = [];

        foreach ($this->getActiveModes($playerName) as $mode) {
            foreach ($mode->attributes as $stat => $value) {
                $bonuses[$stat] = ($bonuses[$stat] ?? 0) + $value;
            }
        }

        return $bonuses;
    }

    /**
     * @return array<string, float>
     */
    public function getMultipliers(string $playerName): array
    {
        $multipliers = [];

        foreach ($this->getActiveModes($playerName) as $mode) {
            foreach ($mode->multipliers as $key => $value) {
                $multipliers[$key] = ($multipliers[$key] ?? 1.0) * $value;
            }
        }

        return $multipliers;
    }

    // ───────────────────────── consulta ─────────────────────────

    /** @return array<string, Mode> */
    public function getActiveModes(string $name): array
    {
        $modes = [];

        foreach (array_keys($this->active[strtolower($name)] ?? []) as $modeId) {
            $mode = $this->plugin->getModeRegistry()->get((string) $modeId);

            if ($mode !== null) {
                $modes[$mode->getId()] = $mode;
            }
        }

        return $modes;
    }

    public function isActive(string $name, string $modeId): bool
    {
        return isset($this->active[strtolower($name)][strtolower($modeId)]);
    }

    // ───────────────────────── ligar e desligar ─────────────────────────

    /** Devolve null se ligou, ou a mensagem do erro. */
    public function activate(Player $player, string $modeId): ?string
    {
        $name = $player->getName();
        $mode = $this->plugin->getModeRegistry()->get($modeId);

        if ($mode === null) {
            return "§cEsse modo não existe.";
        }

        if ($this->isActive($name, $mode->getId())) {
            return "§e{$mode->name} já está ligado.";
        }

        if ($mode->permission !== "" && !$player->hasPermission($mode->permission)) {
            return "§cVocê não tem permissão para usar {$mode->name}.";
        }

        $manager = $this->plugin->getDataManager();
        $level = $manager->getLevel($name);

        if ($level < $mode->requiredLevel) {
            return "§cVocê precisa ser nível §f{$mode->requiredLevel}§c para usar {$mode->name}.";
        }

        // Precisa dar para pagar pelo menos o primeiro ciclo, senão o modo ligaria e
        // desligaria no mesmo instante.
        if ($mode->manaPerCycle > 0 && !$manager->hasEnoughMana($name, $mode->manaPerCycle)) {
            return "§cMana insuficiente para manter {$mode->name}.";
        }

        foreach ($this->getActiveModes($name) as $other) {
            if ($other->group === $mode->group) {
                $this->deactivate($player, $other->getId(), "§7{$other->name} desligou para dar lugar a {$mode->name}.");
            }
        }

        $expiresAt = $mode->duration > 0
            ? $this->plugin->getServer()->getTick() + $mode->duration * 20
            : 0;

        $this->active[strtolower($name)][$mode->getId()] = $expiresAt;

        $this->applyEffects($player, $mode);

        // O bônus só existe depois de o modo entrar no mapa, então o refresh vem por último.
        $manager->refresh($name);

        return null;
    }

    public function deactivate(Player $player, string $modeId, ?string $message = null): bool
    {
        $name = $player->getName();
        $mode = $this->plugin->getModeRegistry()->get($modeId);

        if ($mode === null || !$this->isActive($name, $mode->getId())) {
            return false;
        }

        unset($this->active[strtolower($name)][$mode->getId()]);

        $this->removeEffects($player, $mode);
        $this->plugin->getDataManager()->refresh($name);

        if ($message !== null) {
            $player->sendMessage($message);
        }

        return true;
    }

    public function deactivateAll(Player $player): void
    {
        foreach (array_keys($this->getActiveModes($player->getName())) as $modeId) {
            $this->deactivate($player, (string) $modeId);
        }
    }

    /** Some com o estado de quem saiu. */
    public function forget(string $name): void
    {
        unset($this->active[strtolower($name)]);
    }

    // ───────────────────────── ciclo ─────────────────────────

    /**
     * Cobra a mana dos modos ligados e desliga o que não se sustenta mais.
     *
     * Chamado pelo ModeTickTask. Percorre uma cópia da lista porque desligar um modo
     * altera o mapa no meio do laço.
     */
    public function tick(): void
    {
        $manager = $this->plugin->getDataManager();
        $tick = $this->plugin->getServer()->getTick();

        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            $name = $player->getName();

            if (empty($this->active[strtolower($name)])) {
                continue;
            }

            foreach ($this->getActiveModes($name) as $mode) {
                $expiresAt = $this->active[strtolower($name)][$mode->getId()] ?? 0;

                if ($expiresAt > 0 && $tick >= $expiresAt) {
                    $this->deactivate($player, $mode->getId(), "§7{$mode->name} acabou.");
                    continue;
                }

                if ($mode->manaPerCycle <= 0) {
                    continue;
                }

                if (!$manager->removeMana($name, $mode->manaPerCycle)) {
                    $this->deactivate($player, $mode->getId(), "§cSua mana acabou: {$mode->name} desligou.");
                }
            }
        }
    }

    // ───────────────────────── efeitos de poção ─────────────────────────

    private function applyEffects(Player $player, Mode $mode): void
    {
        foreach ($mode->effects as $entry) {
            $effect = StringToEffectParser::getInstance()->parse($entry["effect"]);

            if ($effect === null) {
                $this->plugin->getLogger()->warning(
                    "[modos.yml] '{$mode->getId()}' pede o efeito '{$entry['effect']}', que o jogo não conhece."
                );
                continue;
            }

            // Duração enorme em vez de infinita: o modo é quem tira o efeito ao desligar,
            // e um valor finito garante que um desligamento perdido não deixe o buff eterno.
            $player->getEffects()->add(new EffectInstance($effect, 20 * 60 * 60, $entry["amplifier"], false, false));
        }
    }

    private function removeEffects(Player $player, Mode $mode): void
    {
        foreach ($mode->effects as $entry) {
            $effect = StringToEffectParser::getInstance()->parse($entry["effect"]);

            if ($effect !== null) {
                $player->getEffects()->remove($effect);
            }
        }
    }
}
