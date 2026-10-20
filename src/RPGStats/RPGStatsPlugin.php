<?php

namespace RPGStats;

use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use RPGStats\Command\ManaCommand;
use RPGStats\Command\ModeCommand;
use RPGStats\Command\SkillCommand;
use RPGStats\Command\LevelCommand;
use RPGStats\Command\ModifierCommand;
use RPGStats\Command\PointsCommand;
use RPGStats\Command\StatsCommand;
use RPGStats\Command\TrainingCommand;
use RPGStats\Command\TitlesCommand;
use RPGStats\Form\DialogueMenu;
use RPGStats\Form\StatsMenu;
use RPGStats\Listener\PlayerListener;
use RPGStats\Manager\CombatTracker;
use RPGStats\Manager\HudManager;
use RPGStats\Manager\ModeManager;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\Manager\SkillManager;
use RPGStats\Mode\ModeRegistry;
use RPGStats\Modifier\ModifierRegistry;
use RPGStats\Skill\SkillRegistry;
use RPGStats\Task\AutoSaveTask;
use RPGStats\Task\ModeTickTask;
use RPGStats\Task\HudTask;
use RPGStats\Task\RegenTask;
use RPGStats\libs\npcdialogue\NpcDialogueManager;

class RPGStatsPlugin extends PluginBase
{
    private PlayerDataManager $dataManager;
    private CombatTracker $combatTracker;
    private HudManager $hudManager;
    private StatsMenu $statsMenu;
    private DialogueMenu $dialogueMenu;

    private ModifierRegistry $classRegistry;
    private ModifierRegistry $rankRegistry;
    private ModifierRegistry $titleRegistry;

    private SkillRegistry $skillRegistry;
    private ModeRegistry $modeRegistry;
    private SkillManager $skillManager;
    private ModeManager $modeManager;

    protected function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->saveResource("classes.yml");
        $this->saveResource("ranks.yml");
        $this->saveResource("titulos.yml");
        $this->saveResource("modos.yml");
        $this->saveResource("habilidades.yml");

        $this->classRegistry = new ModifierRegistry($this, "classe", "classes.yml");
        $this->rankRegistry = new ModifierRegistry($this, "rank", "ranks.yml");
        $this->titleRegistry = new ModifierRegistry($this, "título", "titulos.yml");
        $this->skillRegistry = new SkillRegistry($this);
        $this->modeRegistry = new ModeRegistry($this);

        $this->dataManager = new PlayerDataManager($this);
        $this->combatTracker = new CombatTracker();
        $this->hudManager = new HudManager($this, $this->dataManager);
        $this->statsMenu = new StatsMenu($this);
        $this->dialogueMenu = new DialogueMenu($this);
        $this->skillManager = new SkillManager($this);
        $this->modeManager = new ModeManager($this);

        // O ModeManager é um StatProvider: registrando-o aqui, o bônus de um modo ligado
        // entra em getStat() como se fosse de classe ou título, sem caso especial no cálculo.
        $this->dataManager->registerProvider("modes", $this->modeManager);

        // A tela de diálogo precisa de um NPC falso; a lib cuida disso, mas só depois de registrada.
        if (!NpcDialogueManager::isRegistered()) {
            NpcDialogueManager::register($this);
        }

        $pluginManager = $this->getServer()->getPluginManager();
        $pluginManager->registerEvents(
            new PlayerListener($this, $this->dataManager, $this->combatTracker, $this->hudManager),
            $this,
        );

        $this->registerCommands();
        $this->scheduleTasks();

        // Recarregar o plugin com gente online: puxa quem já está no servidor.
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->dataManager->load($player->getName());
            $this->dataManager->applyAttributes($player);
        }
    }

    protected function onDisable(): void
    {
        // Última chance de gravar: nada do que mudou desde o último auto-save se perde.
        $this->dataManager->flush();
    }

    private function registerCommands(): void
    {
        $commandMap = $this->getServer()->getCommandMap();

        $commandMap->register("rpgstats", new StatsCommand($this));
        $commandMap->register("rpgstats", new ManaCommand($this));
        $commandMap->register("rpgstats", new ModifierCommand(
            $this,
            $this->classRegistry,
            "classe",
            ["class", "job"],
            "rpgstats.classe",
        ));
        $commandMap->register("rpgstats", new ModifierCommand(
            $this,
            $this->rankRegistry,
            "rank",
            ["ranque"],
            "rpgstats.rank",
        ));
        $commandMap->register("rpgstats", new SkillCommand($this));
        $commandMap->register("rpgstats", new ModeCommand($this));
        $commandMap->register("rpgstats", new TitlesCommand($this));
        $commandMap->register("rpgstats", new PointsCommand($this));
        $commandMap->register("rpgstats", new LevelCommand($this));
        $commandMap->register("rpgstats", new TrainingCommand($this));
    }

    private function scheduleTasks(): void
    {
        $regenInterval = max(1, (int) $this->getConfig()->getNested("regen.interval-ticks", 40));
        $saveInterval = max(20, (int) $this->getConfig()->getNested("save.auto-save-interval-ticks", 6000));

        $this->getScheduler()->scheduleRepeatingTask(new RegenTask($this), $regenInterval);
        $this->getScheduler()->scheduleRepeatingTask(new AutoSaveTask($this), $saveInterval);

        $modeInterval = max(1, (int) $this->getConfig()->getNested("modos.intervalo-ticks", 20));
        $this->getScheduler()->scheduleRepeatingTask(new ModeTickTask($this), $modeInterval);

        // Sem HUD ligada não há por que agendar a tarefa.
        if ($this->hudManager->isEnabled()) {
            $hudInterval = max(1, (int) $this->getConfig()->getNested("hud.interval-ticks", 10));

            $this->getScheduler()->scheduleRepeatingTask(new HudTask($this), $hudInterval);
        }
    }

    /**
     * Redesenha o nome do jogador com o título em exibição.
     *
     * O nametag é o nome puro quando não há título: montar "§7[] §f" vazio deixaria
     * colchetes soltos flutuando sobre a cabeça de quem ainda não conquistou nada.
     */
    public function refreshNameTag(Player $player): void
    {
        $name = $player->getName();
        $titleId = $this->dataManager->getEquippedTitle($name);
        $title = $titleId !== "" ? $this->titleRegistry->get($titleId) : null;

        $player->setNameTag($title === null ? $name : "§7[§b{$title->name}§7] §f{$name}");
    }

    /**
     * Abre a ficha no formato configurado. É o que o /stats abre.
     *
     * A ficha desenhada (`DialogueMenu`) só fica legível com o resource pack do RPGStats
     * instalado — ela é uma tela em branco que o pack pinta com os dados que vão nos
     * botões do diálogo. Sem o pack, o jogador vê esses botões soltos.
     *
     * Por isso `ui.formato` nasce em "formulario": o formulário padrão do Bedrock é feio,
     * mas funciona em qualquer servidor sem pack. Troque para "desenhada" depois de
     * instalar o pack.
     */
    public function openSheet(Player $player): void
    {
        $formato = strtolower((string) $this->getConfig()->getNested("ui.formato", "formulario"));

        if ($formato === "desenhada" || $formato === "dialogo") {
            $this->dialogueMenu->open($player);
            return;
        }

        $this->statsMenu->open($player);
    }

    public function getDataManager(): PlayerDataManager
    {
        return $this->dataManager;
    }

    public function getCombatTracker(): CombatTracker
    {
        return $this->combatTracker;
    }

    public function getHudManager(): HudManager
    {
        return $this->hudManager;
    }

    public function getStatsMenu(): StatsMenu
    {
        return $this->statsMenu;
    }

    public function getDialogueMenu(): DialogueMenu
    {
        return $this->dialogueMenu;
    }

    public function getClassRegistry(): ModifierRegistry
    {
        return $this->classRegistry;
    }

    public function getRankRegistry(): ModifierRegistry
    {
        return $this->rankRegistry;
    }

    public function getTitleRegistry(): ModifierRegistry
    {
        return $this->titleRegistry;
    }

    public function getSkillRegistry(): SkillRegistry
    {
        return $this->skillRegistry;
    }

    public function getModeRegistry(): ModeRegistry
    {
        return $this->modeRegistry;
    }

    public function getSkillManager(): SkillManager
    {
        return $this->skillManager;
    }

    public function getModeManager(): ModeManager
    {
        return $this->modeManager;
    }
}
