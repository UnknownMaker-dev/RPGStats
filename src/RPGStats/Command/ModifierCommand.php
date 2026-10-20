<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\Modifier\Modifier;
use RPGStats\Modifier\ModifierRegistry;
use RPGStats\RPGStatsPlugin;

/**
 * Comando dos modificadores de slot único: /race e /family.
 *
 * As duas mecânicas são idênticas (o jogador tem no máximo uma), então a mesma
 * classe atende as duas — muda só o registry, o nome e a permissão.
 */
class ModifierCommand extends Command
{
    /** @param string[] $aliases */
    public function __construct(
        private RPGStatsPlugin $plugin,
        private ModifierRegistry $registry,
        string $name,
        array $aliases,
        string $permission,
    ) {
        parent::__construct(
            $name,
            "Gerencia a " . $registry->getLabel() . " dos jogadores",
            "/{$name} [jogador] | /{$name} <list|info|set|remove> ...",
            $aliases,
        );

        $this->setPermission($permission);
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador ou use §f/{$this->getName()} list");
                return false;
            }

            return $this->show($sender, $sender->getName());
        }

        $action = strtolower($args[0]);

        if ($action === "list") {
            return $this->listAll($sender);
        }

        if ($action === "info") {
            if (count($args) < 2) {
                $sender->sendMessage("§cUso: /{$this->getName()} info <id>");
                return false;
            }

            return $this->info($sender, $args[1]);
        }

        if ($action === "set") {
            if (count($args) < 3) {
                $sender->sendMessage("§cUso: /{$this->getName()} set <jogador> <id>");
                return false;
            }

            return $this->set($sender, $args[1], $args[2]);
        }

        if ($action === "remove") {
            if (count($args) < 2) {
                $sender->sendMessage("§cUso: /{$this->getName()} remove <jogador>");
                return false;
            }

            return $this->set($sender, $args[1], "");
        }

        // Qualquer outra coisa é tratada como nome de jogador.
        return $this->show($sender, $args[0]);
    }

    private function show(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $current = $this->isRace()
            ? $manager->getPlayerClass($targetName)
            : $manager->getRank($targetName);

        $label = $this->registry->getLabel();

        if ($current === null) {
            $sender->sendMessage("§7{$targetName} não tem {$label}. Veja as opções com §f/{$this->getName()} list");
            return true;
        }

        $sender->sendMessage("§6=== " . ucfirst($label) . " de §f{$targetName} §6===");
        $this->describe($sender, $current);

        return true;
    }

    private function listAll(CommandSender $sender): bool
    {
        $all = $this->registry->all();

        if (count($all) === 0) {
            $sender->sendMessage("§cNenhuma {$this->registry->getLabel()} configurada.");
            return true;
        }

        $sender->sendMessage("§6=== " . ucfirst($this->registry->getLabel()) . "s disponíveis ===");

        foreach ($all as $id => $modifier) {
            $sender->sendMessage("§7- §f{$id} §7({$modifier->name}§7)");
        }

        $sender->sendMessage("§8Detalhes: /{$this->getName()} info <id>");

        return true;
    }

    private function info(CommandSender $sender, string $id): bool
    {
        $modifier = $this->registry->get($id);

        if ($modifier === null) {
            $sender->sendMessage("§c'{$id}' não existe. Veja §f/{$this->getName()} list");
            return false;
        }

        $sender->sendMessage("§6=== §f{$modifier->name} §6===");
        $this->describe($sender, $modifier);

        return true;
    }

    private function set(CommandSender $sender, string $targetName, string $id): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $applied = $this->isRace()
            ? $manager->setPlayerClass($targetName, $id)
            : $manager->setRank($targetName, $id);

        $label = $this->registry->getLabel();

        if (!$applied) {
            $sender->sendMessage("§c'{$id}' não existe. Veja §f/{$this->getName()} list");
            return false;
        }

        if ($id === "") {
            $sender->sendMessage("§a{$targetName} está sem {$label} agora.");
            return true;
        }

        $name = $this->registry->get($id)?->name ?? $id;
        $sender->sendMessage("§a{$targetName} agora é §f{$name}§a.");

        $target = $this->plugin->getServer()->getPlayerExact($targetName);
        $target?->sendMessage("§7Sua {$label} agora é §f{$name}§7.");

        return true;
    }

    private function describe(CommandSender $sender, Modifier $modifier): void
    {
        if ($modifier->description !== "") {
            $sender->sendMessage("§8{$modifier->description}");
        }

        $bonuses = $modifier->describeBonuses();

        if (count($bonuses) === 0) {
            $sender->sendMessage("§8Sem bônus.");
            return;
        }

        foreach ($bonuses as $line) {
            $sender->sendMessage("  " . $line);
        }
    }

    private function isRace(): bool
    {
        return $this->registry === $this->plugin->getClassRegistry();
    }
}
