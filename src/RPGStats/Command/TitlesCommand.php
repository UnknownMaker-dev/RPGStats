<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\Modifier\Modifier;
use RPGStats\RPGStatsPlugin;

/**
 * Títulos são cumulativos: ao contrário de classe e rank, o jogador pode ter vários.
 */
class TitlesCommand extends Command
{
    public function __construct(private RPGStatsPlugin $plugin)
    {
        parent::__construct(
            'titulo',
            'Mostra e gerencia os títulos dos jogadores',
            '/titulo [jogador] | usar <id> | <list|info|add|remove|clear> ...',
            ["titulos", "title"]
        );

        // Sem acento no nome: título com "í" é impossível de digitar em boa parte dos
        // teclados de console e celular, que é onde o Bedrock roda.
        $this->setPermission("rpgstats.titulo");
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador ou use §f/titulo list");
                return false;
            }

            return $this->show($sender, $sender->getName());
        }

        $action = strtolower($args[0]);

        if ($action === "usar" || $action === "equipar") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cSó um jogador pode exibir um título.");
                return false;
            }

            return $this->equip($sender, $args[1] ?? "");
        }

        if ($action === "list") {
            return $this->listAll($sender);
        }

        if ($action === "info") {
            if (count($args) < 2) {
                $sender->sendMessage("§cUso: /titulo info <id>");
                return false;
            }

            return $this->info($sender, $args[1]);
        }

        if ($action === "add" || $action === "remove") {
            if (count($args) < 3) {
                $sender->sendMessage("§cUso: /titulo {$action} <jogador> <id>");
                return false;
            }

            return $this->change($sender, $action, $args[1], $args[2]);
        }

        if ($action === "clear") {
            if (count($args) < 2) {
                $sender->sendMessage("§cUso: /titulo clear <jogador>");
                return false;
            }

            return $this->clear($sender, $args[1]);
        }

        return $this->show($sender, $args[0]);
    }

    private function show(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $titles = $manager->getTitles($targetName);

        if (count($titles) === 0) {
            $sender->sendMessage("§7{$targetName} não tem títulos. Veja as opções com §f/titulo list");
            return true;
        }

        $sender->sendMessage("§6=== Títulos de §f{$targetName} §6===");

        foreach ($titles as $title) {
            $sender->sendMessage("§7- §f{$title->name} §8({$title->getId()})");

            foreach ($title->describeBonuses() as $line) {
                $sender->sendMessage("  " . $line);
            }
        }

        return true;
    }

    private function listAll(CommandSender $sender): bool
    {
        $all = $this->plugin->getTitleRegistry()->all();

        if (count($all) === 0) {
            $sender->sendMessage("§cNenhum título configurado.");
            return true;
        }

        $sender->sendMessage("§6=== Títulos disponíveis ===");

        foreach ($all as $id => $title) {
            $sender->sendMessage("§7- §f{$id} §7({$title->name}§7)");
        }

        $sender->sendMessage("§8Detalhes: /titulo info <id>");

        return true;
    }

    private function info(CommandSender $sender, string $id): bool
    {
        $title = $this->plugin->getTitleRegistry()->get($id);

        if ($title === null) {
            $sender->sendMessage("§c'{$id}' não existe. Veja §f/titulo list");
            return false;
        }

        $sender->sendMessage("§6=== §f{$title->name} §6===");
        $this->describe($sender, $title);

        return true;
    }

    private function change(CommandSender $sender, string $action, string $targetName, string $id): bool
    {
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        if (!$this->plugin->getTitleRegistry()->exists($id)) {
            $sender->sendMessage("§c'{$id}' não existe. Veja §f/titulo list");
            return false;
        }

        $name = $this->plugin->getTitleRegistry()->get($id)?->name ?? $id;

        if ($action === "add") {
            if (!$manager->addTitle($targetName, $id)) {
                $sender->sendMessage("§c{$targetName} já tem esse título.");
                return true;
            }

            $sender->sendMessage("§a{$targetName} ganhou o título §f{$name}§a.");
            $this->plugin->getServer()->getPlayerExact($targetName)
                ?->sendMessage("§7Você ganhou o título §f{$name}§7.");

            return true;
        }

        if (!$manager->removeTitle($targetName, $id)) {
            $sender->sendMessage("§c{$targetName} não tem esse título.");
            return true;
        }

        $sender->sendMessage("§a{$targetName} perdeu o título §f{$name}§a.");
        $this->plugin->getServer()->getPlayerExact($targetName)
            ?->sendMessage("§7Você perdeu o título §f{$name}§7.");

        return true;
    }

    private function clear(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();

        if (!$manager->clearTitles($targetName)) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $sender->sendMessage("§aTítulos de §f{$targetName} §aforam removidos.");

        return true;
    }

    private function describe(CommandSender $sender, Modifier $title): void
    {
        if ($title->description !== "") {
            $sender->sendMessage("§8{$title->description}");
        }

        $bonuses = $title->describeBonuses();

        if (count($bonuses) === 0) {
            $sender->sendMessage("§8Sem bônus.");
            return;
        }

        foreach ($bonuses as $line) {
            $sender->sendMessage("  " . $line);
        }
    }

    /**
     * Escolhe qual título aparece junto do nome. "" ou "nenhum" tira o título do nome.
     *
     * Exibir não é o mesmo que ter: todos os títulos conquistados continuam dando bônus,
     * este comando só decide qual deles o resto do servidor vê.
     */
    private function equip(Player $player, string $id): bool
    {
        $manager = $this->plugin->getDataManager();
        $name = $player->getName();
        $id = strtolower($id);

        if ($id === "" || $id === "nenhum" || $id === "none") {
            $manager->setEquippedTitle($name, "");
            $this->plugin->refreshNameTag($player);
            $player->sendMessage("§7Título removido do seu nome.");

            return true;
        }

        if (!$manager->setEquippedTitle($name, $id)) {
            $player->sendMessage("§cVocê não tem esse título. Veja /titulo para os seus.");
            return false;
        }

        $this->plugin->refreshNameTag($player);

        $title = $this->plugin->getTitleRegistry()->get($id);
        $player->sendMessage("§aAgora você exibe §b{$title->name}§a.");

        return true;
    }
}
