<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

/**
 * Pontos de atributo ganhos ao subir de nível.
 *
 * Este é o único comando do plugin que o jogador comum usa: ver e gastar os próprios
 * pontos. Dar e tirar ponto de outro continua sendo coisa de op (rpgstats.points.admin).
 */
class PointsCommand extends Command
{
    private const ADMIN_PERMISSION = "rpgstats.points.admin";

    public function __construct(private RPGStatsPlugin $plugin)
    {
        parent::__construct(
            "points",
            "Mostra e distribui os pontos ganhos ao subir de nível",
            "/points [jogador] | /points spend <atributo> [qtd] | /points <add|remove|set> <jogador> <qtd>",
            ["pontos"],
        );
        $this->setPermission("rpgstats.points");
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        // /points → os próprios pontos
        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador: /points <jogador>");
                return false;
            }

            $this->plugin->getStatsMenu()->openPoints($sender);

            return true;
        }

        $action = strtolower($args[0]);

        if ($action === "spend" || $action === "gastar") {
            return $this->spend($sender, array_slice($args, 1));
        }

        if (in_array($action, ["add", "remove", "set"], true)) {
            return $this->manage($sender, $action, array_slice($args, 1));
        }

        // /points <jogador>
        return $this->showPoints($sender, $args[0]);
    }

    /** /points spend <atributo> [qtd] — o jogador gastando os próprios pontos. */
    private function spend(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cSó um jogador pode gastar pontos.");
            return false;
        }

        if (count($args) === 0) {
            $sender->sendMessage("§cUso: /points spend <atributo> [quantidade]");
            $sender->sendMessage("§7Atributos: §f" . implode(", ", PlayerDataManager::ATTRIBUTES));
            return false;
        }

        $stat = strtolower($args[0]);
        $amount = isset($args[1]) ? (int) $args[1] : 1;
        $name = $sender->getName();
        $manager = $this->plugin->getDataManager();

        if (!in_array($stat, PlayerDataManager::ATTRIBUTES, true)) {
            $sender->sendMessage("§cAtributo inválido! Use: §f" . implode(", ", PlayerDataManager::ATTRIBUTES));
            return false;
        }

        if ($amount <= 0) {
            $sender->sendMessage("§cA quantidade deve ser um número positivo.");
            return false;
        }

        $available = $manager->getPoints($name);

        if ($available < $amount) {
            $sender->sendMessage("§cVocê tem §f{$available} §cponto(s) e tentou gastar §f{$amount}§c.");
            return true;
        }

        if (!$manager->spendPoints($name, $stat, $amount)) {
            $sender->sendMessage("§cNão foi possível gastar os pontos.");
            return true;
        }

        $sender->sendMessage(
            "§a+{$amount} §7em §f" . strtoupper($stat) .
            " §7(agora §e{$manager->getStat($name, $stat)}§7). " .
            "Pontos restantes: §e{$manager->getPoints($name)}"
        );

        return true;
    }

    /** /points <add|remove|set> <jogador> <qtd> — só para quem tem a permissão de admin. */
    private function manage(CommandSender $sender, string $action, array $args): bool
    {
        if (!$sender->hasPermission(self::ADMIN_PERMISSION)) {
            $sender->sendMessage("§cVocê não pode alterar os pontos de outros jogadores.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage("§cUso: /points <add|remove|set> <jogador> <quantidade>");
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

        $result = match ($action) {
            "add" => $manager->addPoints($targetName, $amount),
            "remove" => $manager->removePoints($targetName, $amount),
            "set" => $manager->setPoints($targetName, $amount),
        };

        if (!$result) {
            $sender->sendMessage("§cFalha ao alterar os pontos.");
            return true;
        }

        $sender->sendMessage(
            "§aSucesso! §f{$targetName} §7agora tem §e{$manager->getPoints($targetName)} §7ponto(s)."
        );

        return true;
    }

    private function showPoints(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $points = $manager->getPoints($targetName);

        $sender->sendMessage("§6=== Pontos de §f{$targetName} §6===");
        $sender->sendMessage("§6Pontos livres: §f{$points}");
        $sender->sendMessage(
            "§7Nível §f{$manager->getLevel($targetName)} §7· §e{$manager->getPointsPerLevel()} §7ponto(s) por nível"
        );

        if ($points > 0 && $sender instanceof Player && $sender->getName() === $targetName) {
            $sender->sendMessage("§7Gaste com §f/points spend <atributo> <qtd>§7.");
            $sender->sendMessage("§7Atributos: §f" . implode(", ", PlayerDataManager::ATTRIBUTES));
        }

        return true;
    }
}
