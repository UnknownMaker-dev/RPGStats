<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

/**
 * TP — pontos de treino, ganhos batendo e trocados por atributo.
 *
 * Moeda paralela à dos níveis: /points vem de matar e subir de nível, o TP vem do
 * treino em si. As duas compram atributo, mas o TP cobra mais caro conforme o
 * atributo sobe, porque não tem teto do lado de quem ganha.
 *
 * Não se chama /tp: esse nome é do teleporte do próprio PocketMine.
 */
class TrainingCommand extends Command
{
    private const ADMIN_PERMISSION = "rpgstats.training.admin";

    /** Teto por uso, para um erro de digitação não virar uma compra gigante. */
    private const MAX_PER_USE = 100;

    public function __construct(private RPGStatsPlugin $plugin)
    {
        parent::__construct(
            "training",
            "Mostra e gasta o TP ganho treinando",
            "/training [jogador] | /training spend <atributo> [qtd] | /training <add|remove|set> <jogador> <qtd>",
            ["treino", "treinar"],
        );
        $this->setPermission("rpgstats.training");
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        // /training → o próprio TP
        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador: /training <jogador>");
                return false;
            }

            $this->plugin->getStatsMenu()->openTraining($sender);

            return true;
        }

        $action = strtolower($args[0]);

        if ($action === "spend" || $action === "gastar") {
            return $this->spend($sender, array_slice($args, 1));
        }

        if (in_array($action, ["add", "remove", "set"], true)) {
            return $this->manage($sender, $action, array_slice($args, 1));
        }

        // /training <jogador>
        return $this->showTraining($sender, $args[0]);
    }

    /** /training spend <atributo> [qtd] — o jogador trocando o próprio TP. */
    private function spend(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cSó um jogador pode gastar TP.");
            return false;
        }

        if (count($args) === 0) {
            $sender->sendMessage("§cUso: /training spend <atributo> [quantidade]");
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

        if ($amount <= 0 || $amount > self::MAX_PER_USE) {
            $sender->sendMessage("§cA quantidade deve estar entre 1 e " . self::MAX_PER_USE . ".");
            return false;
        }

        $cost = $manager->getTrainingCostFor($name, $stat, $amount);
        $available = $manager->getTp($name);

        if ($available < $cost) {
            $sender->sendMessage(
                "§cCusta §f{$cost} §cTP e você tem §f{$available}§c. Faltam §f" . ($cost - $available) . "§c."
            );
            return true;
        }

        if (!$manager->spendTraining($name, $stat, $amount)) {
            $sender->sendMessage("§cNão foi possível gastar o TP.");
            return true;
        }

        $base = $manager->getBaseStat($name, $stat);

        $sender->sendMessage(
            "§a+{$amount} §7em §f" . strtoupper($stat) . " §7por §e{$cost} §7TP (agora §e{$base}§7)."
        );
        $sender->sendMessage(
            "§7TP restante: §e{$manager->getTp($name)} §8· próximo ponto: " .
            $manager->getTrainingCost($base) . " TP"
        );

        return true;
    }

    /** /training <add|remove|set> <jogador> <qtd> — só para quem tem a permissão de admin. */
    private function manage(CommandSender $sender, string $action, array $args): bool
    {
        if (!$sender->hasPermission(self::ADMIN_PERMISSION)) {
            $sender->sendMessage("§cVocê não pode alterar o TP de outros jogadores.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage("§cUso: /training <add|remove|set> <jogador> <quantidade>");
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
            "add" => $manager->addTp($targetName, $amount),
            "remove" => $manager->removeTp($targetName, $amount),
            "set" => $manager->setTp($targetName, $amount),
        };

        if (!$result) {
            $sender->sendMessage("§cFalha ao alterar o TP.");
            return true;
        }

        $sender->sendMessage("§aSucesso! §f{$targetName} §7agora tem §e{$manager->getTp($targetName)} §7de TP.");

        return true;
    }

    private function showTraining(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $tp = $manager->getTp($targetName);

        $sender->sendMessage("§6=== Treino de §f{$targetName} §6===");
        $sender->sendMessage("§eTP: §f{$tp}");
        $sender->sendMessage("§7Custo do próximo ponto:");

        foreach (PlayerDataManager::ATTRIBUTES as $stat) {
            $cost = $manager->getTrainingCost($manager->getBaseStat($targetName, $stat));
            $color = $tp >= $cost ? "§a" : "§8";

            $sender->sendMessage("  {$color}" . strtoupper($stat) . "§7: §f{$cost} §7TP");
        }

        if ($sender instanceof Player && $sender->getName() === $targetName) {
            $sender->sendMessage("§7Troque com §f/training spend <atributo> <qtd>§7.");
        }

        return true;
    }
}
