<?php

namespace Tests\Feature;

use App\Services\Deadlock\NeedCalculator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeadlockNeedTest extends TestCase
{
    public function test_valid_form_displays_calculated_need_without_database_access(): void
    {
        DB::shouldReceive('connection')->never();
        $need = [[4, 2, 3], [1, 0, 7]];

        $this->post('/interbloqueos', [
            'n' => '2', 'm' => '3',
            'allocation' => '[[1,0,3],[2,4,1]]',
            'max' => '[[5,2,6],[3,4,8]]',
            'available' => '[0,0,0]',
        ])->assertOk()
            ->assertViewHas('need', $need)
            ->assertSeeText('Need — Matriz de necesidad')
            ->assertSeeText('Need[i][j] = Max[i][j] - Allocation[i][j]')
            ->assertSee('<pre>'.json_encode($need, JSON_PRETTY_PRINT).'</pre>', false);
    }

    public function test_zero_need_is_displayed(): void
    {
        $this->postJson('/interbloqueos', [
            'n' => 1, 'm' => 1, 'allocation' => [[0]], 'max' => [[0]], 'available' => [0],
        ])->assertOk()->assertViewHas('need', [[0]])
            ->assertSee('<pre>'.json_encode([[0]], JSON_PRETTY_PRINT).'</pre>', false);
    }

    public function test_initial_page_does_not_calculate_or_display_need(): void
    {
        $this->mock(NeedCalculator::class)->shouldNotReceive('calculate');

        $this->get('/interbloqueos')->assertOk()->assertViewMissing('need')
            ->assertDontSeeText('Need — Matriz de necesidad');
    }

    public function test_invalid_scenario_does_not_reach_the_calculator(): void
    {
        $this->mock(NeedCalculator::class)->shouldNotReceive('calculate');

        $this->from('/interbloqueos')->post('/interbloqueos', [
            'n' => 1, 'm' => 1, 'allocation' => [[2]], 'max' => [[1]], 'available' => [0],
        ])->assertRedirect('/interbloqueos')->assertSessionHasErrors('allocation.0.0');
        $this->get('/interbloqueos')->assertOk()->assertViewMissing('need')
            ->assertDontSeeText('Need — Matriz de necesidad');
    }
}
