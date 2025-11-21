<?php

namespace App\Service;

use App\Entity\Tourney;
use App\Entity\TourneyGame;
use App\Entity\TourneyTeam;
use App\Exception\ServiceException;

abstract class TourneyRuleGroupStage extends TourneyRule implements GroupStageAwareRule
{
    private TourneyRule $knockoutRule;

    public function __construct(Tourney $tourney, SettingService $settingService)
    {
        parent::__construct($tourney, $settingService);
        $this->knockoutRule = $this->createKnockoutRule($tourney, $settingService);
    }

    abstract protected function createKnockoutRule(Tourney $tourney, SettingService $settingService): TourneyRule;

    public function seed(array $list): void
    {
        $this->validateConfiguration(count($list));
        foreach ($this->tourney->getTeams() as $team) {
            $team->setGroupKey(null);
        }
        $groups = $this->distributeTeams($list);
        $this->createGroupMatches($groups);
    }

    public function processGame(TourneyGame $game, bool $overwrite): void
    {
        if ($game->isGroupStage()) {
            if ($this->areAllGroupGamesCompleted()) {
                $this->seedKnockoutIfRequired();
            }
            return;
        }

        $this->knockoutRule->processGame($game, $overwrite);
    }

    public function podium(): array
    {
        if (!$this->hasKnockoutBracket()) {
            return [];
        }

        return $this->knockoutRule->podium();
    }

    public function getFinal(): ?TourneyGame
    {
        return $this->hasKnockoutBracket() ? $this->knockoutRule->getFinal() : null;
    }

    public function getTrees(): array
    {
        if (!$this->hasKnockoutBracket()) {
            return [];
        }

        return $this->knockoutRule->getTrees();
    }

    public function isCompleted(): bool
    {
        return $this->hasKnockoutBracket() && $this->knockoutRule->isCompleted();
    }

    public function getGroupTables(): array
    {
        $tables = [];
        foreach ($this->tourney->getTeams() as $team) {
            $groupKey = $team->getGroupKey();
            if (!$groupKey) {
                continue;
            }
            if (!isset($tables[$groupKey])) {
                $tables[$groupKey] = [];
            }
            $tables[$groupKey][$team->getId() ?? spl_object_hash($team)] = $this->createEmptyRow($team);
        }

        foreach ($this->tourney->getGames() as $game) {
            if (!$game->isGroupStage() || !$game->isSeeded() || !$game->isDone()) {
                continue;
            }
            $groupKey = $game->getGroupKey();
            $teamA = $game->getTeamA();
            $teamB = $game->getTeamB();
            if (!$groupKey || !$teamA || !$teamB) {
                continue;
            }
            if (!isset($tables[$groupKey])) {
                continue;
            }

            $keyA = $teamA->getId() ?? spl_object_hash($teamA);
            $keyB = $teamB->getId() ?? spl_object_hash($teamB);
            if (!isset($tables[$groupKey][$keyA]) || !isset($tables[$groupKey][$keyB])) {
                continue;
            }

            $this->applyResult($tables[$groupKey][$keyA], $tables[$groupKey][$keyB], $game->getScoreA(), $game->getScoreB());
        }

        ksort($tables);
        foreach ($tables as &$standings) {
            usort($standings, fn (array $a, array $b) => $this->compareStandings($a, $b));
        }

        return $tables;
    }

    public function hasKnockoutBracket(): bool
    {
        return !empty($this->getKnockoutGames());
    }

    protected function collectAdvancingTeams(): array
    {
        $advance = max(0, (int) $this->tourney->getGroupAdvance());
        if ($advance === 0) {
            return [];
        }

        $qualified = [];
        foreach ($this->getGroupTables() as $standings) {
            $slice = array_slice($standings, 0, $advance);
            foreach ($slice as $entry) {
                $qualified[] = $entry['team'];
            }
        }

        return $qualified;
    }

    private function validateConfiguration(int $teamCount): void
    {
        $groupCount = $this->tourney->getGroupCount();
        $advance = $this->tourney->getGroupAdvance();
        if (empty($groupCount) || empty($advance)) {
            throw new ServiceException(ServiceException::CAUSE_INVALID, 'Gruppenkonfiguration ist unvollständig.');
        }
        if ($groupCount < 1 || $advance < 1) {
            throw new ServiceException(ServiceException::CAUSE_INVALID, 'Gruppenkonfiguration muss positive Zahlen verwenden.');
        }
        if ($groupCount > $teamCount) {
            throw new ServiceException(ServiceException::CAUSE_INVALID, 'Mehr Gruppen als Teams sind nicht erlaubt.');
        }
        $maxPerGroup = (int) ceil($teamCount / $groupCount);
        if ($advance > $maxPerGroup) {
            throw new ServiceException(ServiceException::CAUSE_INVALID, 'Mehr Qualifikanten als Teams pro Gruppe sind nicht möglich.');
        }
    }

    /**
     * @param array<string, array<TourneyTeam>> $groups
     */
    private function createGroupMatches(array $groups): void
    {
        foreach ($groups as $groupKey => $teams) {
            $count = count($teams);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $game = (new TourneyGame())
                        ->setGroupStage(true)
                        ->setGroupKey($groupKey)
                        ->setTeamA($teams[$i])
                        ->setTeamB($teams[$j]);
                    $this->tourney->addGame($game);
                }
            }
        }
    }

    /**
     * @return array<string, array<TourneyTeam>>
     */
    private function distributeTeams(array $teams): array
    {
        $groupCount = (int) $this->tourney->getGroupCount();
        $groups = [];
        for ($i = 0; $i < $groupCount; $i++) {
            $groups[$this->groupLabel($i)] = [];
        }
        $groupKeys = array_keys($groups);
        foreach ($teams as $index => $team) {
            if (!$team instanceof TourneyTeam) {
                continue;
            }
            $slot = $groupKeys[$index % $groupCount];
            $team->setGroupKey($slot);
            $groups[$slot][] = $team;
        }

        return $groups;
    }

    private function groupLabel(int $position): string
    {
        $label = '';
        $positionCopy = $position;
        do {
            $label = chr(ord('A') + ($positionCopy % 26)) . $label;
            $positionCopy = intdiv($positionCopy, 26) - 1;
        } while ($positionCopy >= 0);

        return $label;
    }

    private function areAllGroupGamesCompleted(): bool
    {
        foreach ($this->tourney->getGames() as $game) {
            if ($game->isGroupStage() && !$game->isDone()) {
                return false;
            }
        }
        return true;
    }

    private function seedKnockoutIfRequired(): void
    {
        if ($this->hasKnockoutBracket()) {
            return;
        }
        $qualified = $this->collectAdvancingTeams();
        if (count($qualified) < 2) {
            return;
        }
        $this->knockoutRule->seed($qualified);
    }

    /**
     * @return TourneyGame[]
     */
    private function getKnockoutGames(): array
    {
        return array_values(array_filter(
            $this->tourney->getGames()->toArray(),
            fn (TourneyGame $game) => !$game->isGroupStage()
        ));
    }

    private function createEmptyRow(TourneyTeam $team): array
    {
        return [
            'team' => $team,
            'points' => 0,
        ];
    }

    private function applyResult(array &$rowA, array &$rowB, ?int $scoreA, ?int $scoreB): void
    {
        if ($scoreA === null || $scoreB === null) {
            return;
        }

        if ($scoreA > $scoreB) {
            $rowA['points'] += 3;
        } elseif ($scoreB > $scoreA) {
            $rowB['points'] += 3;
        } else {
            $rowA['points']++;
            $rowB['points']++;
        }
    }

    private function compareStandings(array $a, array $b): int
    {
        return $b['points'] <=> $a['points']
            ?: strcmp($a['team']->getName() ?? '', $b['team']->getName() ?? '');
    }
}
