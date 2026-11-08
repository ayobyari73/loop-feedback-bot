<?php

namespace App\Services\Reports;

use App\Models\Feedback;
use App\Models\Member;
use App\Models\ScoreRule;
use App\Services\Jalali;
use App\Services\ProfileStats;
use App\Services\Season;
use Illuminate\Support\Collection;

/**
 * Collects everything a dashboard or a text export shows, once, so the HTML and the Markdown
 * always tell the same numbers.
 */
class ReportData
{
    public function __construct(private ProfileStats $stats) {}

    /**
     * The community report for a month or a season.
     *
     * @return array<string, mixed>
     */
    public function forPeriod(Period $period): array
    {
        $feedback = ProfileStats::inRange($period->start, $period->end)->with(['from', 'to'])->orderBy('created_at')->get();
        $previous = $period->previous();
        $previousFeedback = ProfileStats::inRange($previous->start, $previous->end)->get(['kind']);
        $summaries = $this->stats->forRows($feedback);

        $involvedIds = array_keys($summaries);
        $people = Member::where('active', true)->orWhereIn('id', $involvedIds)->get()
            ->map(fn (Member $member) => ['member' => $member, 'summary' => $summaries[$member->id] ?? $this->stats->blank()])
            ->sortBy([fn (array $a, array $b) => $b['summary']['points'] <=> $a['summary']['points'], fn (array $a, array $b) => strcmp((string) $a['member']->name, (string) $b['member']->name)])
            ->values();

        return [
            'period' => $period,
            'previous' => $previous,
            'generatedAt' => $this->now(),
            'rules' => ScoreRule::table(),
            'totals' => $this->totals($feedback, $summaries),
            'previousTotals' => ['feedback' => $previousFeedback->count(), 'kinds' => $this->countKinds($previousFeedback)],
            'activeMembers' => Member::where('active', true)->count(),
            'timeline' => $this->timeline($feedback, $period),
            'people' => $people,
            'pairs' => $this->pairs($feedback),
            'negatives' => $feedback->filter(fn (Feedback $item) => $item->isNegative())->values(),
            'feedback' => $feedback,
        ];
    }

    /**
     * One looper's full report: this month, this season, all time, a 12-month trend and every feedback.
     *
     * @return array<string, mixed>
     */
    public function forMember(Member $member): array
    {
        $all = Feedback::with(['from', 'to'])
            ->where(fn ($query) => $query->where('to_member_id', $member->id)->orWhere('from_member_id', $member->id))
            ->orderBy('created_at')->get();
        $month = Period::currentMonth();
        $season = Period::currentSeason();
        $inPeriod = fn (Period $period) => $all->filter(fn (Feedback $item) => $item->created_at->gte($period->start) && $item->created_at->lt($period->end));

        $seasonRanking = collect($this->stats->forRange($season->start, $season->end))
            ->only(Member::where('active', true)->pluck('id')->all())
            ->sortByDesc('points')->keys()->values();
        $rank = $seasonRanking->search($member->id);

        return [
            'member' => $member,
            'generatedAt' => $this->now(),
            'rules' => ScoreRule::table(),
            'month' => $month,
            'season' => $season,
            'summaries' => [
                'month' => $this->stats->forRows($inPeriod($month))[$member->id] ?? $this->stats->blank(),
                'season' => $this->stats->forRows($inPeriod($season))[$member->id] ?? $this->stats->blank(),
                'total' => $this->stats->forRows($all)[$member->id] ?? $this->stats->blank(),
            ],
            'rank' => $rank === false ? null : $rank + 1,
            'rankedCount' => max($seasonRanking->count(), Member::where('active', true)->count()),
            'monthly' => $this->monthly($all, $member),
            'counterparts' => $this->counterparts($all, $member),
            'received' => $all->where('to_member_id', $member->id)->sortByDesc('created_at')->values(),
            'given' => $all->where('from_member_id', $member->id)->sortByDesc('created_at')->values(),
        ];
    }

    private function now(): string
    {
        return Jalali::format(now()).' '.now(config('loop.timezone'))->format('H:i');
    }

    /**
     * @param  Collection<int, Feedback>  $feedback
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array{feedback: int, givers: int, receivers: int, participants: int, kinds: array<string, int>}
     */
    private function totals(Collection $feedback, array $summaries): array
    {
        return [
            'feedback' => $feedback->count(),
            'givers' => $feedback->pluck('from_member_id')->unique()->count(),
            'receivers' => $feedback->pluck('to_member_id')->unique()->count(),
            'participants' => count($summaries),
            'kinds' => $this->countKinds($feedback),
        ];
    }

    /**
     * @param  Collection<int, Feedback>  $feedback
     * @return array<string, int>
     */
    private function countKinds(Collection $feedback): array
    {
        $counts = array_fill_keys(array_keys(Feedback::KINDS), 0);
        foreach ($feedback as $item) {
            $counts[$item->kind]++;
        }

        return $counts;
    }

    /**
     * Daily counts for a month, weekly counts for a season.
     *
     * @param  Collection<int, Feedback>  $feedback
     * @return list<array{label: string, count: int}>
     */
    private function timeline(Collection $feedback, Period $period): array
    {
        $step = $period->type === 'month' ? 1 : 7;
        $buckets = [];
        for ($cursor = $period->start->copy(); $cursor->lt($period->end); $cursor->addDays($step)) {
            [, $m, $d] = Jalali::of($cursor);
            $buckets[] = ['label' => $period->type === 'month' ? (string) $d : $d.' '.Jalali::MONTHS[$m], 'count' => 0];
        }

        foreach ($feedback as $item) {
            $index = intdiv((int) $period->start->diffInDays($item->created_at->copy()->setTimezone(config('loop.timezone'))), $step);
            if (isset($buckets[$index])) {
                $buckets[$index]['count']++;
            }
        }

        return $buckets;
    }

    /**
     * The busiest "who recorded value from whom" pairs.
     *
     * @param  Collection<int, Feedback>  $feedback
     * @return list<array{from: Member, to: Member, count: int, kinds: array<string, int>}>
     */
    private function pairs(Collection $feedback): array
    {
        return $feedback->groupBy(fn (Feedback $item) => $item->from_member_id.'-'.$item->to_member_id)
            ->map(fn (Collection $group) => [
                'from' => $group->first()->from,
                'to' => $group->first()->to,
                'count' => $group->count(),
                'kinds' => $this->countKinds($group),
            ])
            ->sortByDesc('count')->take(10)->values()->all();
    }

    /**
     * Received and given counts for the last 12 Jalali months, oldest first.
     *
     * @param  Collection<int, Feedback>  $all
     * @return list<array{label: string, received: int, given: int}>
     */
    private function monthly(Collection $all, Member $member): array
    {
        $current = Jalali::monthIndex(now());
        $months = [];
        for ($index = $current - 11; $index <= $current; $index++) {
            $months[$index] = ['label' => Jalali::monthName($index), 'short' => Jalali::MONTHS[$index % 12 + 1], 'received' => 0, 'given' => 0];
        }

        foreach ($all as $item) {
            $index = Jalali::monthIndex($item->created_at);
            if (isset($months[$index])) {
                $months[$index][$item->to_member_id === $member->id ? 'received' : 'given']++;
            }
        }

        return array_values($months);
    }

    /**
     * Everyone this looper exchanged feedback with, busiest first.
     *
     * @param  Collection<int, Feedback>  $all
     * @return list<array{member: Member, received: int, given: int}>
     */
    private function counterparts(Collection $all, Member $member): array
    {
        $rows = [];
        foreach ($all as $item) {
            $receivedIt = $item->to_member_id === $member->id;
            $other = $receivedIt ? $item->from : $item->to;
            $rows[$other->id] ??= ['member' => $other, 'received' => 0, 'given' => 0];
            $rows[$other->id][$receivedIt ? 'received' : 'given']++;
        }

        return collect($rows)->sortByDesc(fn (array $row) => $row['received'] + $row['given'])->values()->all();
    }
}
