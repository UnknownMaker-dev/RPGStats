<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\RPGStatsPlugin;

/**
 * Nível e progresso de XP.
 *
 * Não se chama /xp de propósito: o PocketMine já registra um /xp para a experiência
 * do jogo, e o nome repetido só resolveria como /rpgstats:xp.
 */
class LevelCommand extends Command
{
    private const ADMIN_PERMISSION = "rpgstats.level.admin";

    /** Quantos traços a barra de progresso tem. */
    private const BAR_WIDTH = 20;

    public function __construct(private RPGStatsPlugin $plugin)
    {
        parent::__construct(
            "level",
            "Mostra o nível e o progresso de XP",
            "/level [jogador] | /level <add|remove|set> <jogador> <xp>",
            ["nivel", "exp"],
        );
        $this->setPermission("rpgstats.level");
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        // /level → o próprio nível
        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador: /level <jogador>");
                return false;
            }

            return $this->showLevel($sender, $sender->getName());
        }

        $action = strtolower($args[0]);

        if (in_array($action, ["add", "remove", "set"], true)) {
            return $this->manage($sender, $action, array_slice($args, 1));
        }

        // /level <jogador>
        return $this->showLevel($sender, $args[0]);
    }

    /** /level <add|remove|set> <jogador> <xp> — mexe no XP; só o add faz subir de nível. */
    private function manage(CommandSender $sender, string $action, array $args): bool
    {
        if (!$sender->hasPermission(self::ADMIN_PERMISSION)) {
            $sender->sendMessage("§cVocê não pode alterar o XP de outros jogadores.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage("§cUso: /level <add|remove|set> <jogador> <xp>");
            return false;
        }

        $targetName = $args[0];
        $amount = (int) $args[1];
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        if ($amount < 0 || ($amount === 0 && $action !== "set")) {
            $sender->sendMessage("§cA quantidade deve ser um número positivo.");
            return false;
        }

        if ($action === "add") {
            if ($manager->isMaxLevel($targetName)) {
                $sender->sendMessage("§cJogador §f{$targetName} §cjá está no nível máximo.");
                return true;
            }

            $levels = $manager->addXp($targetName, $amount);
            $target = $this->plugin->getServer()->getPlayerExact($targetName);

            if ($levels > 0 && $target !== null) {
                $points = $levels * $manager->getPointsPerLevel();

                $target->sendMessage("§6§lNÍVEL {$manager->getLevel($targetName)}§r§6!");
                $target->sendMessage("§7Você ganhou §e{$points} §7ponto(s). Use §f/points spend <atributo> <qtd>§7.");
            }

            $extra = $levels > 0 ? " §7(subiu §e{$levels} §7nível(is))" : "";
            $sender->sendMessage("§aSucesso! §f{$targetName} §7recebeu §e{$amount} §7de XP{$extra}");

            return true;
        }

        $result = $action === "remove"
            ? $manager->removeXp($targetName, $amount)
            : $manager->setXp($targetName, $amount);

        if (!$result) {
            $sender->sendMessage("§cFalha ao alterar o XP.");
            return true;
        }

        // O set trava um abaixo do necessário: subir de nível é só pelo add, que paga os pontos.
        $sender->sendMessage(
            "§aSucesso! §f{$targetName} §7está com §e{$manager->getXp($targetName)} §7de XP no nível " .
            "§f{$manager->getLevel($targetName)}§7."
        );

        return true;
    }

    private function showLevel(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $level = $manager->getLevel($targetName);
        $xp = $manager->getXp($targetName);

        $sender->sendMessage("§6=== Nível de §f{$targetName} §6===");

        if ($manager->isMaxLevel($targetName)) {
            $sender->sendMessage("§dNível: §f{$level} §7(máximo)");
            $sender->sendMessage($this->renderBar(1, 1));
        } else {
            $needed = $manager->getXpForLevel($level);

            $sender->sendMessage("§dNível: §f{$level} §7/ §f{$manager->getMaxLevel()}");
            $sender->sendMessage("§bXP: §f{$xp} §7/ §f{$needed} §7(faltam §f{$manager->getXpToNextLevel($targetName)}§7)");
            $sender->sendMessage($this->renderBar($xp, $needed));
        }

        $sender->sendMessage("§6Pontos livres: §f{$manager->getPoints($targetName)}");

        return true;
    }

    private function renderBar(int $current, int $needed): string
    {
        $ratio = $needed > 0 ? min(1.0, $current / $needed) : 1.0;
        $filled = (int) round($ratio * self::BAR_WIDTH);

        return "§a" . str_repeat("|", $filled) . "§8" . str_repeat("|", self::BAR_WIDTH - $filled);
    }
}
