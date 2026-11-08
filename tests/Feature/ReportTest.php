<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\Jalali;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    private Member $admin;

    private Member $sara;

    private Member $ali;

    private int $aban;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Asia/Tehran'));
        $this->aban = Jalali::monthIndex(now());
        $this->admin = $this->admin(['name' => 'ادمین']);
        $this->sara = Member::factory()->create(['name' => 'سارا احمدی', 'username' => 'sara_ahmadi']);
        $this->ali = Member::factory()->create(['name' => 'علی رضایی', 'username' => 'ali_rezaei']);
    }

    private function give(Member $from, Member $to, string $kind, string $text, Carbon $at): Feedback
    {
        return Feedback::factory()->create([
            'from_member_id' => $from->id, 'to_member_id' => $to->id, 'kind' => $kind, 'description' => $text, 'created_at' => $at->copy()->utc(),
        ]);
    }

    /**
     * @return array<string, string> file name => contents
     */
    private function documentsSentTo(int $chatId): array
    {
        $documents = [];
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if (! str_contains($request->url(), 'sendDocument')) {
                continue;
            }
            $parts = collect($request->data())->keyBy('name');
            if ((int) $parts['chat_id']['contents'] === $chatId) {
                $documents[$parts['document']['filename']] = (string) $parts['document']['contents'];
            }
        }

        return $documents;
    }

    public function test_the_reports_button_offers_this_month_last_month_and_the_season(): void
    {
        $this->sayAs($this->admin, '📊 گزارش‌ها');

        $data = array_column($this->lastButtonsSentTo($this->admin->telegram_id), 'callback_data');
        $this->assertContains("rp:m:{$this->aban}", $data);
        $this->assertContains('rp:m:'.($this->aban - 1), $data);
        $this->assertContains('rp:s:0', $data);
        $this->assertContains('📅 ماه آبان 1405', array_column($this->lastButtonsSentTo($this->admin->telegram_id), 'text'));
    }

    public function test_a_monthly_report_sends_a_summary_an_html_dashboard_and_a_markdown_export(): void
    {
        $this->give($this->ali, $this->sara, 'knowledge', 'آموزش لاراول', now()->subDays(2));
        $this->give($this->ali, $this->sara, 'network', 'معرفی به سرمایه‌گذار', now()->subDays(1));
        $this->give($this->sara, $this->ali, 'negative', 'جلسه رو نیومد', now());

        $this->clickAs($this->admin, "rp:m:{$this->aban}");

        $this->assertMessageSentTo($this->admin->telegram_id, 'فیدبک‌های این ماه: <b>3</b>');
        $files = $this->documentsSentTo($this->admin->telegram_id);
        $this->assertSame(['loop-1405-08.html', 'loop-1405-08.md'], array_keys($files));

        $html = $files['loop-1405-08.html'];
        $this->assertStringContainsString('<html lang="fa" dir="rtl">', $html);
        $this->assertStringContainsString('داشبورد فیدبک لوپ — ماه آبان 1405', $html);
        $this->assertStringContainsString('سارا احمدی', $html);
        $this->assertStringContainsString('معرفی به سرمایه‌گذار', $html);
        $this->assertStringContainsString('<svg class="chart"', $html);

        $markdown = $files['loop-1405-08.md'];
        $this->assertStringContainsString('# گزارش فیدبک لوپ — ماه آبان 1405', $markdown);
        $this->assertStringContainsString('## راهنمای داده', $markdown);
        $this->assertStringContainsString('| 1 | سارا احمدی | @sara_ahmadi | 2 | 1 | 1 | 0 | 0 | 0 | 0 | 0 | 1 | 1 | 1 | لوپر |', $markdown);
        $this->assertStringContainsString('| علی رضایی | سارا احمدی | knowledge | آموزش لاراول |', $markdown);
    }

    public function test_a_month_follows_jalali_boundaries_in_tehran_time(): void
    {
        $this->give($this->ali, $this->sara, 'knowledge', 'آخرین شب مهر', Carbon::parse('2026-10-22 23:30:00', 'Asia/Tehran'));
        $this->give($this->ali, $this->sara, 'knowledge', 'اولین بامداد آبان', Carbon::parse('2026-10-23 00:30:00', 'Asia/Tehran'));

        $this->clickAs($this->admin, "rp:m:{$this->aban}");

        $markdown = $this->documentsSentTo($this->admin->telegram_id)['loop-1405-08.md'];
        $this->assertStringContainsString('اولین بامداد آبان', $markdown);
        $this->assertStringNotContainsString('آخرین شب مهر', $markdown);
        $this->assertStringContainsString('| 📚 دانش (knowledge) | 1 | 1 |', $markdown);
    }

    public function test_the_season_report_covers_the_whole_season(): void
    {
        $this->give($this->ali, $this->sara, 'experience', 'در مهر', Carbon::parse('2026-10-01', 'Asia/Tehran'));
        $this->give($this->ali, $this->sara, 'experience', 'در آبان', now());

        $this->sayAs($this->admin, '/season');

        $this->assertMessageSentTo($this->admin->telegram_id, 'فیدبک‌های این فصل: <b>2</b>');
        $markdown = $this->documentsSentTo($this->admin->telegram_id)['loop-season-1.md'];
        $this->assertStringContainsString('در مهر', $markdown);
        $this->assertStringContainsString('در آبان', $markdown);
    }

    public function test_feedback_text_cannot_inject_html_into_the_dashboard_or_break_the_markdown_table(): void
    {
        $this->give($this->ali, $this->sara, 'knowledge', "<script>alert(1)</script> a|b\nخط دوم", now());

        $this->clickAs($this->admin, "rp:m:{$this->aban}");

        $files = $this->documentsSentTo($this->admin->telegram_id);
        $this->assertStringNotContainsString('<script>', $files['loop-1405-08.html']);
        $this->assertStringContainsString('&lt;script&gt;', $files['loop-1405-08.html']);
        $this->assertStringContainsString('a\\|b خط دوم', $files['loop-1405-08.md']);
    }

    public function test_a_personal_report_has_both_files_with_received_and_given_feedback_and_the_season_rank(): void
    {
        $this->give($this->ali, $this->sara, 'knowledge', 'آموزش لاراول', now());
        $this->give($this->admin, $this->sara, 'network', 'معرفی', now());
        $this->give($this->sara, $this->ali, 'experience', 'تجربه‌ی مذاکره', now());

        $this->clickAs($this->admin, "pr:{$this->sara->id}");

        $files = $this->documentsSentTo($this->admin->telegram_id);
        $this->assertCount(2, $files);
        $html = collect($files)->first(fn ($contents, $name) => str_ends_with($name, '.html'));
        $markdown = collect($files)->first(fn ($contents, $name) => str_ends_with($name, '.md'));

        $this->assertStringContainsString('گزارش شخصی — سارا احمدی', $html);
        $this->assertStringContainsString('تجربه‌ی مذاکره', $html);
        $this->assertStringContainsString('- رتبه در فصل 1 (1405/07/01 تا 1405/09/30): 1 از 3', $markdown);
        $this->assertStringContainsString('## فیدبک‌های گرفته (2)', $markdown);
        $this->assertStringContainsString('## فیدبک‌های داده (1)', $markdown);
        $this->assertStringContainsString('| آبان 1405 | 2 | 1 |', $markdown);
    }

    public function test_only_admins_see_the_personal_report_button_and_loopers_cannot_request_reports(): void
    {
        $this->sayAs($this->sara, '👤 پروفایل من');
        $this->assertNotContains("pr:{$this->sara->id}", array_column($this->lastButtonsSentTo($this->sara->telegram_id), 'callback_data'));

        $this->clickAs($this->sara, "pr:{$this->sara->id}");
        $this->clickAs($this->sara, "rp:m:{$this->aban}");
        $this->sayAs($this->sara, '/season');

        $this->assertSame([], $this->documentsSentTo($this->sara->telegram_id));

        $this->clickAs($this->admin, "pf:{$this->sara->id}");
        $this->assertContains("pr:{$this->sara->id}", array_column($this->lastButtonsSentTo($this->admin->telegram_id), 'callback_data'));
    }

    public function test_a_future_period_is_not_reported(): void
    {
        $this->clickAs($this->admin, 'rp:m:'.($this->aban + 1));

        $this->assertSame([], $this->documentsSentTo($this->admin->telegram_id));
    }

    public function test_an_empty_month_still_produces_a_readable_dashboard(): void
    {
        $this->clickAs($this->admin, 'rp:m:'.($this->aban - 1));

        $files = $this->documentsSentTo($this->admin->telegram_id);
        $this->assertStringContainsString('فیدبکی ثبت نشده', $files['loop-1405-07.html']);
        $this->assertStringContainsString('فیدبکی ثبت نشده.', $files['loop-1405-07.md']);
    }
}
