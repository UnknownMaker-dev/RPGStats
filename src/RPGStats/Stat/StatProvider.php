<?php

namespace RPGStats\Stat;

/**
 * Fonte externa de bônus de atributo.
 *
 * É por aqui que plugins de fora — armadura com status, item equipado, buff temporário —
 * entram na conta do RPGStats sem que ele precise conhecer nenhum deles.
 *
 * **Os dois métodos são chamados com muita frequência**: `getStat()` roda em todo cálculo
 * de dano, em toda tarefa de regeneração e a cada atualização de HUD. A implementação
 * precisa devolver um valor já pronto em memória. Varrer o inventário aqui dentro derruba
 * o TPS do servidor — o certo é manter um mapa por jogador, atualizado nos eventos de
 * inventário, e só devolvê-lo.
 */
interface StatProvider
{
    /**
     * Bônus somados ao valor base do atributo.
     *
     * As chaves precisam estar em PlayerDataManager::ATTRIBUTES; o que não estiver é
     * ignorado em silêncio, porque um provider não pode inventar atributo novo.
     *
     * @return array<string, int>
     */
    public function getAttributeBonuses(string $playerName): array;

    /**
     * Multiplicadores compostos por multiplicação com os de classe, rank e títulos.
     *
     * As chaves precisam estar em Modifier::MULTIPLIERS. Devolver 1.0 é o mesmo que não
     * devolver a chave.
     *
     * @return array<string, float>
     */
    public function getMultipliers(string $playerName): array;
}
