<?php
/**
 *  Bu yazılım Elektrik Elektronik Teknolojileri Alanı/Elektrik Öğretmeni Hakan GÜLEN tarafından geliştirilmiş olup geliştirilen bütün kaynak kodlar
 *   Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International (CC BY-NC-SA 4.0) ile lisanslanmıştır.
 *  Ayrıntılı lisans bilgisi için https://creativecommons.org/licenses/by-nc-sa/4.0/legalcode.tr sayfasını ziyaret edebilirsiniz. 2019
 */

namespace App\Http\Controllers\Api\Question;

use App\Events\QuestionEvalCalculateRequired;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\ResponseHelper;
use App\Models\Branch;
use App\Models\Question;
use App\Models\QuestionEvalRequest;
use App\Traits\QuestionEvalTraits;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\JsonResponse;
use Yajra\DataTables\DataTables;

class QuestionEvalRequestController extends ApiController
{
    use QuestionEvalTraits;

    /**
     * Soru değerlendirme isteğini ilgili değerlendiricilere ekleyen api
     * @param $questionId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create($questionId, Request $request)
    {
        $validationResult = $this->apiValidator($request, [
            'electors' => 'required|array|min:2|max:5'
        ]);
        if ($validationResult) {
            return response()->json($validationResult, 422);
        }

        $electors = $request->input('electors');
        // Değerlendirmeler bir kod vererek gruplamaya çalışıyoruz
        // Yani bir değerlendirme isteğinde bir grup değerlendiriciye değerlendirme yaptırıyoruz
        // Bu değerlendirmeleri de grup kodu vasıtasıyla ayrıştırıyoruz.
        // Aşırı bir örnek olsa da bir soruyu iki farklı değerlendirici grubuna değerlentirtmek
        // isteyebiliriz bu durumda grup değerlendirmelerinin karışmasının önüne geçmiş olacağız.
        $code = strtoupper(Str::random(6));
        try {
            DB::beginTransaction();
            foreach ($electors as $elector) {
                $qevalReq = new QuestionEvalRequest([
                    'elector_id' => $elector['id'],
                    'creator_id' => Auth::id(),
                    'question_id' => $questionId,
                    'code' => $code
                ]);
                $qevalReq->save();
                //TODO Değerlendiriciye mail atmak gerekebilir
            }
            Question::where('id', $questionId)
                ->where('status', Question::WAITING_FOR_ACTION)
                ->orWhere('status', Question::REVISION_COMPLETED)
                ->update(['status' => Question::IN_ELECTION]);
            DB::commit();
            return response()->json([ResponseHelper::MESSAGE => 'Değerlendirme isteği ilgli değerlendircilere iltildi.'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            return response()->json($this->apiException($exception), 500);
        }
    }

    /**
     * Olası bir değerlendirme isteği silinmesinde aynı gruba yeni
     * değerlendirici ekleyebilen api
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addNewElector(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $validationResult = $this->apiValidator($request, [
            'elector_id' => 'required|array|min:2|max:5',
            'question_id' => 'required',
            'qer_id' => 'required'
        ]);
        if ($validationResult) {
            return response()->json($validationResult, 422);
        }
        $qerId = $request->input('qer_id');
        $electorId = $request->input('elector_id');
        $questionId = $request->input('question_id');
        $qer = QuestionEvalRequest::findOrFail($qerId);
        try {
            DB::beginTransaction();
            $qevalReq = new QuestionEvalRequest([
                'elector_id' => $electorId,
                'creator_id' => Auth::id(),
                'question_id' => $questionId,
                'code' => $qer->code
            ]);
            $qevalReq->save();
            DB::commit();
            return response()->json([ResponseHelper::MESSAGE => 'Gruba yeni değerlendirici kaydı başarıyla eklendi.'], 200);
        } catch (\Exception $exception) {
            DB::rollBack();
            return response()->json($this->apiException($exception), 500);
        }
    }

    public function delete($id)
    {
        try {
            $qer = QuestionEvalRequest::findOrFail($id);
            DB::beginTransaction();
            $qer->delete();
            DB::commit();
            // Olası değerlendirme silinmesi durumunda yeniden hesaplama yapılması gerekir
            event(new QuestionEvalCalculateRequired($qer));
            return response()->json([ResponseHelper::MESSAGE => 'Değerlendirme isteği başarıyla silindi.'], 200);
        } catch (\Exception $exception) {
            DB::rollBack();
            return response()->json($this->apiException($exception), 500);
        }
    }

    public function deleteByCode($questionId, $code)
    {
        try {
            DB::beginTransaction();
            QuestionEvalRequest::where('code', $code)->delete();
            Question::where('id', $questionId)
                ->where('status', Question::IN_ELECTION)
                ->update(['status' => Question::WAITING_FOR_ACTION]);
            DB::commit();
            // Olası değerlendirme silinmesi durumunda yeniden hesaplama yapılması gerekir
            // QuestionEvalRequest::where('code', $code);
            // event(new QuestionEvalCalculateRequired($qer));
            return response()->json([ResponseHelper::MESSAGE => 'Değerlendirme isteği başarıyla silindi.'], 200);
        } catch (\Exception $exception) {
            DB::rollBack();
            return response()->json($this->apiException($exception), 500);
        }
    }

    /**
     * Değerlendiricinin isteğe yanıt vermesini sağlayan api
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateQuestionEval(Request $request): ?JsonResponse
    {
        $validationResult = $this->apiValidator($request, [
            'qer_id' => 'required', // QuestionEvaluationRequest Id
            'point' => 'required|min:1|max:5',
            'comment' => 'required|max:1000'
        ]);
        if ($validationResult) {
            return response()->json($validationResult, 422);
        }

        $qerId = $request->input('qer_id');
        $point = $request->input('point');
        $comment = $request->input('comment');

        try {
            $qer = QuestionEvalRequest::findOrFail($qerId);
            DB::beginTransaction();
            $qer->point = $point;
            $qer->comment = $comment;
            $qer->is_open = false;
            $qer->save();
            DB::commit();
            // Event fırlatarak soru puanlama ve hesaplamaları yaptırılıyor
            event(new QuestionEvalCalculateRequired($qer));
            return response()->json([ResponseHelper::MESSAGE => 'Değerlendirmeniz kaydedilmiştir. Teşekkür ederiz.'], 201);
        } catch (\Exception $exception) {
            DB::rollBack();
            return response()->json($this->apiException($exception), 500);
        }
    }

    /**
     * Bu fonksiyon manuel hesaplama için kullanılacaktır
     * @param Request $request
     * @return JsonResponse
     */
    public function manualCalculate(Request $request): ?JsonResponse
    {
        $validationResult = $this->apiValidator($request, [
            'question_id' => 'required', // QuestionEvaluationRequest Id
            'code' => 'required',
        ]);
        if ($validationResult) {
            return response()->json($validationResult, 422);
        }

        $questionId = $request->input('question_id');
        $code = $request->input('code');
        try {
            if ($this->checkQuestionEval($questionId, $code)) {
                // TODO Refactoring yapılacak
                $this->setQuestionState($this->calculateQuestionEval($questionId, $code), $questionId);
                return response()->json([ResponseHelper::MESSAGE => 'Soru değerlendirmesi maunel hesaplanmıştır.'], 200);
            }
            return response()->json([ResponseHelper::MESSAGE => 'Maalesef yeterli değerlendirme yapılmamıştır.'], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json($this->apiException($exception), 500);
        }
    }

    public function findByQuestionId($questionId)
    {
        $res = DB::table('question_eval_requests as qer')
            ->join('users as u', 'u.id', '=', 'qer.elector_id')
            ->where('qer.question_id', $questionId)
            ->select('qer.id', 'qer.elector_id',
                'u.full_name',
                'qer.code',
                'qer.comment',
                'qer.point',
                'qer.is_open',
                'qer.updated_at')
            ->get();
        return response()->json($res);
    }

    public function getQuestionRequestsList(Request $request)
    {
        // TODO Branch id sosyalciler ve Adminler için parametrik olacak ve istemciden gelecek
        // TODO Durum bilgisi kullanıcıdan alınacak

        $branchId = $request->input('branch_id');

        //TODO başlangıç ve bitiş tarihlerine göre de sınırlama yapılabilir
//        $startData = $endDate = null;

        $user = Auth::user();
        $table = DB::table('question_eval_requests as qer')
            ->join('questions as q', 'q.id', '=', 'qer.question_id')
            ->join('branches as b', 'b.id', '=', 'q.lesson_id')
            ->join('users as elector', 'elector.id', '=', 'qer.elector_id')
            ->join('users as creator', 'creator.id', '=', 'qer.creator_id')
            ->select('qer.id',
                'qer.question_id',
                'qer.creator_id',
                'qer.elector_id',
                DB::raw('creator.full_name as creator_name'),
                DB::raw('elector.full_name as elector_name'),
                'qer.code',
                'qer.point',
                'qer.comment',
                DB::raw('b.name as branch_name'),
                'qer.created_at');

        // Kullanıcı admin değilse çöp sorular saklansın ve sadece kendi soruları listelensin ;-)
        if (!$user->isAn('admin')) {
            $table->where('qer.elector_id', Auth::id());

            if ($user->branch->code === 'SB') {
                $branchIds = Branch::where('code', 'SB')
                    ->orWhere('code', 'İTA')
                    ->get('id');
                // TODO dersler ve braşlar aslında ayrılmalı idi ya da çoklu branş seçimi mümkün olmalı idi
                // Tedbir amaçlı sosyalcilerin gönderdiği branş kodu bilgisi burada kontrol ediliyor ki başka branşlardan listeleme yapamasınlar
                if ($branchIds->contains($branchId)) {
                    $table->where('q.lesson_id', $branchId);
                }
            }
        }
        // Aslında buna çok gerek yok çünkü kullanıcıyı ve soru foreign key ile
        // birleştirildi hem de üstteki kod parçasında kullanıcı id sine göre süzdürme yapıldı
        // Alttaki kod parçası branş bazlı parametrik süzdürme için eklendi
        if ($user->isAn('admin')) {
            if (isset($branchId)) {
                $table->where('q.lesson_id', $branchId);
            }
        }

        return Datatables::of($table)
            ->orderColumn('elector.full_name', 'qer.created_at')
            ->editColumn('created_at', static function ($a) {
                Carbon::setLocale("tr-TR");
                $d = strtotime($a->created_at) > 0 ? with(new Carbon($a->created_at))->formatLocalized("%d.%m.%Y") : '';
                return $d;
            })
            ->filterColumn('created_at', static function ($query, $keyword) {
                $query->whereRaw("DATE_FORMAT(qer.created_at,'%d.%m.%Y') like ?", ["%{$keyword}%"]);
            })
            ->make(true);
    }
}