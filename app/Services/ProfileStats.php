<?php

namespace App\Services;

use App\Models\Feedback;
use App\Models\Member;
use App\Models\ScoreRule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Numbers behind a looper's profile and the season report.
 *
 * For each of the four kinds it counts feedback received and given. Points are the counts multiplied by
 * the owner's current score rules, so changing a rule re-scores all past feedback. Deleted feedback is ignored.
 *
 * @phpstan-type Summary array{received: array<string, int>, given: array<string, int>, points: int, from_people: int, to_people: int}
 */
class ProfileStats
{
    public const WEEKS = 12;

    /**
     * @return array{season: Summary, total: Summary, weekly: list<int>}
     */
    public function forMember(Member $member): array
    {
        $rows = Feedback::where(fn ($query) => $query->where('to_member_id', $member->id)->orWhere('from_member_id', $member->id))
            ->get(['from_member_id', 'to_member_id', 'kind', 'season', 'created_at']);
        $rules = ScoreRule::table();

        return [
            'season' => $this->summarize($rows->where('season', Season::number()), $rules)[$member->id] ?? $this->blank(),
            'total' => $this->summarize($rows, $rules)[$member->id] ?? $this->blank(),
            'weekly' => $this->weekly($rows),
        ];
    }

    /**
     * @return array<int, Summary> keyed by member id
     */
    public function forSeason(int $season): array
    {
        return $this->summarize(
            Feedback::where('season', $season)->get(['from_member_id', 'to_member_id', 'kind', 'season', 'created_at']),
            ScoreRule::table(),
        );
    }

    /**
     * Summaries for feedback created in [start, end).
     *
     * @return array<int, Summary> keyed by member id
     */
    public function forRange(CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->forRows(self::inRange($start, $end)->get(['from_member_id', 'to_member_id', 'kind', 'season', 'created_at']));
    }

    /**
     * @param  Collection<int, Feedback>  $rows
     * @return array<int, Summary> keyed by member id
     */
    public function forRows(Collection $rows): array
    {
        return $this->summarize($rows, ScoreRule::table());
    }

    /**
     * @return Builder<Feedback>
     */
    public static function inRange(CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Feedback::where('created_at', '>=', $start->copy()->utc())->where('created_at', '<', $end->copy()->utc());
    }

    /**
     * Feedback per week across the whole community, oldest week first.
     *
     * @return list<int>
     */
    public function communityWeekly(): array
    {
        $since = $this->weekStart()->subWeeks(self::WEEKS - 1);

        return $this->weekly(Feedback::where('created_at', '>=', $since->copy()->utc())->get(['created_at']));
    }

    /**
     * Percent change of the last 4 weeks against the 4 weeks before, or null when there is nothing to compare with.
     *
     * @param  list<int>  $weekly
     */
    public function growth(array $weekly): ?int
    {
        $recent = array_sum(array_slice($weekly, -4));
        $before = array_sum(array_slice($weekly, -8, 4));

        return $before === 0 ? null : (int) round(($recent - $before) / $before * 100);
    }

    /**
     * @return Summary
     */
    public function blank(): array
    {
        $zeros = array_fill_keys(array_keys(Feedback::KINDS), 0);

        return ['received' => $zeros, 'given' => $zeros, 'points' => 0, 'from_people' => 0, 'to_people' => 0];
    }

    /**
     * One pass over the feedback rows, building a summary for everyone involved.
     *
     * @param  Collection<int, Feedback>  $rows
     * @param  array<string, array{giver: int, receiver: int}>  $rules
     * @return array<int, Summary>
     */
    private function summarize(Collection $rows, array $rules): array
    {
        $summaries = [];
        $fromPeople = [];
        $toPeople = [];

        foreach ($rows as $feedback) {
            $giver = $feedback->from_member_id;
            $receiver = $feedback->to_member_id;
            $summaries[$giver] ??= $this->blank();
            $summaries[$receiver] ??= $this->blank();

            $summaries[$giver]['given'][$feedback->kind]++;
            $summaries[$giver]['points'] += $rules[$feedback->kind]['giver'] ?? 0;
            $toPeople[$giver][$receiver] = true;

            $summaries[$receiver]['received'][$feedback->kind]++;
            $summaries[$receiver]['points'] += $rules[$feedback->kind]['receiver'] ?? 0;
            $fromPeople[$receiver][$giver] = true;
        }

        foreach ($summaries as $memberId => &$summary) {
            $summary['from_people'] = count($fromPeople[$memberId] ?? []);
            $summary['to_people'] = count($toPeople[$memberId] ?? []);
        }

        return $summaries;
    }

    /**
     * Counts rows per week (weeks start on Saturday, Tehran time), oldest week first, current week last.
     *
     * @param  Collection<int, Feedback>  $rows
     * @return list<int>
     */
    private function weekly(Collection $rows): array
    {
        $buckets = array_fill(0, self::WEEKS, 0);
        $thisWeek = $this->weekStart();
        $timezone = config('loop.timezone');

        foreach ($rows as $feedback) {
            $week = $feedback->created_at->copy()->setTimezone($timezone)->startOfWeek(Carbon::SATURDAY);
            $weeksAgo = (int) round($week->diffInWeeks($thisWeek));

            if ($weeksAgo >= 0 && $weeksAgo < self::WEEKS) {
                $buckets[self::WEEKS - 1 - $weeksAgo]++;
            }
        }

        return $buckets;
    }

    private function weekStart(): Carbon
    {
        return now(config('loop.timezone'))->startOfWeek(Carbon::SATURDAY);
    }
}
