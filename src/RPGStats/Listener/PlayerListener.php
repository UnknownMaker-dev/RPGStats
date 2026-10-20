<?php

namespace RPGStats\Listener;

use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\ExplodeSound;
use RPGStats\Manager\CombatTracker;
use RPGStats\Manager\HudManager;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

class PlayerListener implements Listener
{
    private bool $progressionEnabled;
    private int $xpPerPlayerKill;
    private int $xpPerMobKill;

    /** @var array<string, int> nome da entidade em minúsculo => XP */
    private array $xpByEntity;

    private bool $trainingEnabled;
    private float $tpPerDamage;
    private bool $showTrainingGain;

    private float $critMultiplier;

    /** Frase de boas-vindas; o servidor escolhe o vocabulário no config. */
    private string $welcomeMessage;

    public function __construct(
        private RPGStatsPlugin $plugin,
        private PlayerDataManager $dataManager,
        private CombatTracker $combatTracker,
        private HudManager $hudManager,
    ) {
        $config = $plugin->getConfig();

        $this->progressionEnabled = (bool) $config->getNested("progression.enabled", true);
        $this->xpPerPlayerKill = (int) $config->getNested("progression.xp-per-kill.player", 25);
        $this->xpPerMobKill = (int) $config->getNested("progression.xp-per-kill.mob", 5);

        $this->xpByEntity = [];
        foreach ((array) $config->getNested("progression.xp-per-kill.by-entity", []) as $entity => $xp) {
            $this->xpByEntity[strtolower((string) $entity)] = (int) $xp;
        }

        $this->trainingEnabled = (bool) $config->getNested("training.enabled", true);
        $this->tpPerDamage = max(0.0, (float) $config->getNested("training.tp-per-damage", 1.0));
        $this->showTrainingGain = (bool) $config->getNested("training.mostrar-ganho", true);
        $this->critMultiplier = max(1.0, (float) $config->getNested("combat.multiplicador-critico", 2.0));
        $this->welcomeMessage = (string) $config->get(
            "mensagem-de-entrada",
            "§b[Sistema] Bem-vindo de volta, §f{jogador}§b.",
        );
    }

    /**
     * Carrega os dados antes do jogador entrar de fato, para o join já achar tudo no cache.
     *
     * @priority HIGH
     */
    public function onLogin(PlayerLoginEvent $event): void
    {
        $this->dataManager->load($event->getPlayer()->getName());
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $name = $player->getName();
        $manager = $this->dataManager;

        $stats = $manager->getStats($name);

        if ($stats === null) {
            return;
        }

        $player->sendMessage(str_replace("{jogador}", $name, $this->welcomeMessage));
        $player->sendMessage(
            "§7HP: §f{$stats['hp']} §7DANO: §f{$stats['damage']} §7DEF: §f{$stats['defense']} " .
            "§7VEL: §f{$stats['speed']} §7MANA: §f{$stats['mana']} §7VEL.ATK: §f{$stats['atk_speed']}"
        );
        $player->sendMessage("§eTP: §f{$stats['tp']} §7| §bMana: §f{$manager->getMana($name)}§7/§f{$manager->getMaxMana($name)}");
        $player->sendMessage(
            "§dNível: §f{$manager->getLevel($name)} §7| §6Pontos livres: §f{$manager->getPoints($name)}"
        );

        if ($manager->getPoints($name) > 0) {
            $player->sendMessage("§7Use §f/pontos gastar <atributo> <qtd> §7para distribuir.");
        }

        $playerClass = $manager->getPlayerClass($name);
        $rank = $manager->getRank($name);

        if ($playerClass !== null) {
            $player->sendMessage("§7Classe: §f{$playerClass->name}");
        }

        if ($rank !== null) {
            $player->sendMessage("§7Rank: §f{$rank->name}");
        }

        $manager->applyAttributes($player);
        $this->plugin->refreshNameTag($player);

        // Sem isso a HUD ficaria em branco até o primeiro ciclo da tarefa.
        $this->hudManager->update($player);
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $name = $event->getPlayer()->getName();

        // Modo e recarga são estado de sessão: precisam morrer com a sessão, senão o
        // próximo login herda uma transformação ligada que ninguém está pagando.
        $this->plugin->getModeManager()->forget($name);
        $this->plugin->getSkillManager()->forget($name);

        $this->dataManager->unload($name);
        $this->combatTracker->forget($name);
        $this->hudManager->forget($name);
    }

    public function onDamage(EntityDamageByEntityEvent $event): void
    {
        $attacker = $event->getDamager();

        if (!$attacker instanceof Player) {
            return;
        }

        $name = $attacker->getName();
        $manager = $this->dataManager;

        if ($manager->getStats($name) === null) {
            return;
        }

        $damage = $event->getBaseDamage() + $manager->getDamageBonus($name);

        // O crítico multiplica o golpe inteiro, arma incluída — é o que o faz valer a pena
        // contra um alvo blindado, onde só o bônus de atributo se perderia na defesa.
        $chance = $this->plugin->getSkillManager()->getCritChance($name);

        if ($chance > 0 && mt_rand(1, 10000) <= (int) round($chance * 10000)) {
            $damage *= $this->critMultiplier;
            $attacker->sendActionBarMessage("§c§lCRÍTICO!");
            $attacker->getWorld()->addSound($attacker->getPosition(), new ExplodeSound(), [$attacker]);
        }

        $event->setBaseDamage($damage);

        // Velocidade de ataque encurta a recarga entre golpes. O valor é a fração cortada,
        // então 0.25 deixa a recarga em 75% do que seria. O piso de 1 tick existe porque
        // recarga 0 faz o cliente mandar um ataque por tick com o botão pressionado.
        $reduction = $manager->getAttackSpeedBonus($name);

        if ($reduction > 0) {
            $event->setAttackCooldown((int) max(1, round($event->getAttackCooldown() * (1 - $reduction))));
        }
    }

    /**
     * Aplica a defesa de quem levou o dano.
     *
     * Roda em HIGH, ou seja depois do bônus do atacante (NORMAL), para a mitigação
     * incidir sobre o golpe inteiro. A armadura do jogo corta o que sobrar depois.
     *
     * @priority HIGH
     */
    public function onDamageTaken(EntityDamageEvent $event): void
    {
        if ($event->isCancelled()) {
            return;
        }

        // Vazio e /kill precisam matar: defesa nenhuma segura isso.
        if (in_array($event->getCause(), [EntityDamageEvent::CAUSE_VOID, EntityDamageEvent::CAUSE_SUICIDE], true)) {
            return;
        }

        $victim = $event->getEntity();

        if (!$victim instanceof Player) {
            return;
        }

        $name = $victim->getName();

        if ($this->dataManager->getStats($name) === null) {
            return;
        }

        $reduction = $this->dataManager->getDamageReduction($name);

        if ($reduction <= 0) {
            return;
        }

        $event->setBaseDamage($event->getBaseDamage() * (1 - $reduction));
    }

    /**
     * XP para quem matou. Vale para mob e para jogador.
     *
     * PlayerDeathEvent herda de EntityDeathEvent, então este handler cobre os dois.
     */
    public function onDeath(EntityDeathEvent $event): void
    {
        if (!$this->progressionEnabled) {
            return;
        }

        $victim = $event->getEntity();
        $cause = $victim->getLastDamageCause();

        if (!$cause instanceof EntityDamageByEntityEvent) {
            return;
        }

        $killer = $cause->getDamager();

        if (!$killer instanceof Player || $killer === $victim) {
            return;
        }

        $name = $killer->getName();
        $manager = $this->dataManager;

        if ($manager->getStats($name) === null || $manager->isMaxLevel($name)) {
            return;
        }

        $reward = $this->getKillReward($victim);

        if ($reward <= 0) {
            return;
        }

        // O que ele de fato recebe, já com o multiplicador de classe, rank e títulos.
        $effective = $manager->getXpGain($name, $reward);
        $gained = $manager->addXp($name, $reward);

        if ($gained === 0) {
            // Ganho normal: barra de ação, para não encher o chat a cada abate.
            $killer->sendActionBarMessage(
                "§b+{$effective} XP §7(faltam §f{$manager->getXpToNextLevel($name)}§7)"
            );

            return;
        }

        $points = $gained * $manager->getPointsPerLevel();

        $killer->sendMessage("§6§lNÍVEL {$manager->getLevel($name)}§r§6!");
        $killer->sendMessage("§7Você ganhou §e{$points} §7ponto(s). Use §f/pontos gastar <atributo> <qtd>§7.");
    }

    /** Quanto XP a vítima vale, com a tabela do config. */
    private function getKillReward(Living $victim): int
    {
        if ($victim instanceof Player) {
            return $this->xpPerPlayerKill;
        }

        return $this->xpByEntity[strtolower($victim->getName())] ?? $this->xpPerMobKill;
    }

    /**
     * TP de treino para quem acertou o golpe, proporcional ao dano.
     *
     * Roda em MONITOR de propósito: aqui o dano já é o final — passou pelo bônus do
     * atacante, pela defesa do alvo e pela armadura. É o dano que de fato saiu, não
     * o que o atacante tentou dar.
     *
     * @priority MONITOR
     */
    public function onDamageDealt(EntityDamageByEntityEvent $event): void
    {
        if (!$this->trainingEnabled || $event->isCancelled()) {
            return;
        }

        $attacker = $event->getDamager();

        if (!$attacker instanceof Player || $attacker === $event->getEntity()) {
            return;
        }

        $name = $attacker->getName();
        $manager = $this->dataManager;

        if ($manager->getStats($name) === null) {
            return;
        }

        $gain = (int) round($event->getFinalDamage() * $this->tpPerDamage);

        if ($gain <= 0 || !$manager->addTp($name, $gain)) {
            return;
        }

        if ($this->showTrainingGain) {
            $attacker->sendActionBarMessage("§e+{$gain} TP §7(total §f{$manager->getTp($name)}§7)");
        }
    }

    /**
     * Marca quem está em combate — atacante e alvo — para a regeneração esperar a luta acabar.
     *
     * @priority MONITOR
     */
    public function onAnyDamage(EntityDamageEvent $event): void
    {
        if ($event->isCancelled()) {
            return;
        }

        $tick = $this->plugin->getServer()->getTick();
        $entity = $event->getEntity();

        if ($entity instanceof Player) {
            $this->combatTracker->mark($entity->getName(), $tick);
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();

            if ($damager instanceof Player) {
                $this->combatTracker->mark($damager->getName(), $tick);
            }
        }
    }
}
