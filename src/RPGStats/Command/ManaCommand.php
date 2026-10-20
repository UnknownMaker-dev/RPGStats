<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\RPGStatsPlugin;

class ManaCommand extends Command
{
    private RPGStatsPlugin $plugin;

    public function __construct(RPGStatsPlugin $plugin)
    {
        parent::__construct(
            'mana',
            'Mostra e gerencia a mana dos jogadores',
            '/mana [jogador] | /mana <add|remove|set> <jogador> <quantidade>',
            ["mp", "energia"]
        );
        $this->setPermission("rpgstats.mana");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        $manager = $this->plugin->getDataManager();

        // /mana → a própria mana
        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador: /mana <jogador>");
                return false;
            }

            return $this->showMana($sender, $sender->getName());
        }

        if (count($args) === 1) {
            return $this->showMana($sender, $args[0]);
        }

        // /mana <add|remove|set> <jogador> <quantidade>
        if (count($args) < 3) {
            $sender->sendMessage("§cUso: /mana <add|remove|set> <jogador> <quantidade>");
            return false;
        }

        $action = strtolower($args[0]);
        $targetName = $args[1];
        $amount = (int) $args[2];

        if ($amount <= 0) {
            $sender->sendMessage("§cA quantidade deve ser um número positivo.");
            return false;
        }

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $result = match ($action) {
            "add" => $manager->addMana($targetName, $amount),
            "remove" => $manager->removeMana($targetName, $amount),
            "set" => $manager->setMana($targetName, $amount),
            default => null
        };

        if ($result === null) {
            $sender->sendMessage("§cAção inválida! Use: add, remove ou set");
            return false;
        }

        if ($result) {
            $mana = $manager->getMana($targetName);
            $maxMana = $manager->getMaxMana($targetName);
            $sender->sendMessage("§aSucesso! §f{$targetName} §bMana: §f{$mana} §7/ §f{$maxMana}");
        } else {
            $sender->sendMessage("§cFalha! mana insuficiente ou valor inválido.");
        }

        return true;
    }

    private function showMana(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $mana = $manager->getMana($targetName);
        $maxMana = $manager->getMaxMana($targetName);

        $sender->sendMessage("§6=== mana de §f{$targetName} §6===");
        $sender->sendMessage("§bMana: §f{$mana} §7/ §f{$maxMana}");
        $sender->sendMessage("§7Atributo MANA: §f{$manager->getStat($targetName, 'mana')} §8(define o tamanho do poço)");

        return true;
    }
}
