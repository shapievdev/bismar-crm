<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DemonstrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_runs_real_operations_and_preserves_all_five_stage_statuses(): void
    {
        $batch = 'test-'.strtolower(Str::random(12));
        $directory = storage_path('app/onboarding-demo/'.$batch);
        try {
            $this->artisan('onboarding:demo', ['--batch' => $batch])->assertSuccessful();
            $data = json_decode(File::get($directory.'/evidence.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('completed', $data['graduate_final']['status']);
            $this->assertSame(100, $data['graduate_final']['progress']['percent']);
            $this->assertArrayNotHasKey('graduation', $data['graduate_final']);
            $this->assertSame(29, $data['in_progress_final']['progress']['percent']);
            $this->assertCount(5, array_unique(array_column($data['in_progress_final']['stages'], 'status')));
            foreach ($data['checks'] as $check) {
                $this->assertTrue($check['passed'], $check['name']);
            }
            $reminders = array_values(array_filter($data['notifications'], fn ($n) => $n['label'] === 'За 30 минут до встречи'));
            $this->assertCount(2, $reminders);
            $this->assertCount(2, $reminders[0]['captured']);
            $this->assertCount(2, $reminders[1]['captured']);
            $this->assertStringContainsString('12:00', $reminders[0]['captured'][0]['message']['body']);
            $this->assertStringContainsString('15:00', $reminders[1]['captured'][0]['message']['body']);
            $this->assertSame(2, $data['report']['started']);
            $this->assertSame(1, $data['report']['completed']);
            $this->assertSame(50.0, (float) $data['report']['completion_rate']);
            $this->artisan('onboarding:demo', ['--batch' => $batch])->assertSuccessful();
            $this->assertSame(4, User::where('email', 'like', 'onboarding-demo-'.$batch.'-%')->count());
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
