@if(($filters['context']??'')==='client')
<input type="hidden" name="context" value="client">
@foreach(['client_q','client_status','client_page','client_sort'] as $contextKey)
@if(isset($filters[$contextKey]))
<input type="hidden" name="{{ $contextKey }}" value="{{ $filters[$contextKey] }}">
@endif
@endforeach
@endif
