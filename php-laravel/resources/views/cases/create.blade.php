@extends('layouts.app')
@section('title', __('Report a Missing Child'))

@section('content')
<div class="max-w-2xl mx-auto py-10 px-4">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"> {{ __('Report a Missing Child') }}</h1>
        <p class="text-gray-500 mt-1 text-sm">
            {{ __('Fill in as many details as possible. All reports are reviewed by an officer before going live.') }}
        </p>
    </div>

    <form method="POST" action="{{ route('cases.store') }}" enctype="multipart/form-data"
          class="space-y-6 bg-white p-6 rounded-xl shadow-sm border">
        @csrf

        {{-- Errors summary --}}
        @if($errors->any())
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">
                <ul class="list-disc pl-4 space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Child details ─────────────────────────────────────────── --}}
        <fieldset class="space-y-4">
            <legend class="font-semibold text-gray-700 border-b pb-1 w-full">
                {{ __("Child's Details") }}
            </legend>

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __("Child's Full Name") }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="child_name" value="{{ old('child_name') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm @error('child_name') border-red-400 @enderror"
                           required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Age') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="age" value="{{ old('age') }}" min="0" max="17"
                           class="w-full border rounded-lg px-3 py-2 text-sm @error('age') border-red-400 @enderror"
                           required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Gender') }} <span class="text-red-500">*</span>
                    </label>
                    <select name="gender"
                            class="w-full border rounded-lg px-3 py-2 text-sm @error('gender') border-red-400 @enderror">
                        <option value="male"    @selected(old('gender')=='male')>{{ __('Male / Kiume') }}</option>
                        <option value="female"  @selected(old('gender')=='female')>{{ __('Female / Kike') }}</option>
                        <option value="unknown" @selected(old('gender')=='unknown')>{{ __('Unknown / Sijui') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Height (cm)') }}</label>
                    <input type="number" name="height_cm" value="{{ old('height_cm') }}" step="0.1" min="30" max="220"
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Complexion') }}</label>
                    <input type="text" name="complexion" value="{{ old('complexion') }}"
                           placeholder="{{ __('e.g. Dark, Medium, Light') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Clothing Worn') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="clothing" value="{{ old('clothing') }}"
                           placeholder="{{ __('e.g. Blue school uniform, white sandals') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm @error('clothing') border-red-400 @enderror"
                           required>
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Distinctive Features') }}
                    </label>
                    <input type="text" name="distinctive" value="{{ old('distinctive') }}"
                           placeholder="{{ __('Scars, birthmarks, disability') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
        </fieldset>

        {{-- ── Last seen ─────────────────────────────────────────────── --}}
        <fieldset class="space-y-4">
            <legend class="font-semibold text-gray-700 border-b pb-1 w-full">
                {{ __('Last Known Location') }}
            </legend>

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Specific Area') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="last_seen_area" value="{{ old('last_seen_area') }}"
                           placeholder="{{ __('e.g. Mathare Primary School, Mathare') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm @error('last_seen_area') border-red-400 @enderror"
                           required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('County') }} <span class="text-red-500">*</span>
                    </label>
                    <select name="county" id="county-select"
                            class="w-full border rounded-lg px-3 py-2 text-sm @error('county') border-red-400 @enderror"
                            required>
                        <option value="">{{ __('Select county…') }}</option>
                        @foreach(\App\Http\Requests\StoreCaseRequest::KENYAN_COUNTIES as $county)
                            <option value="{{ $county }}" @selected(old('county')==$county)>{{ $county }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Sub-County') }}</label>
                    <input type="text" name="sub_county" value="{{ old('sub_county') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>

                {{-- Hidden lat/lng filled by JS geocoder when county is selected --}}
                <input type="hidden" name="lat" id="lat-input" value="{{ old('lat', -1.286389) }}">
                <input type="hidden" name="lng" id="lng-input" value="{{ old('lng', 36.817223) }}">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Date & Time Last Seen') }} <span class="text-red-500">*</span>
                    </label>
                    <input type="datetime-local" name="missing_since" value="{{ old('missing_since') }}"
                           max="{{ now()->format('Y-m-d\TH:i') }}"
                           class="w-full border rounded-lg px-3 py-2 text-sm @error('missing_since') border-red-400 @enderror"
                           required>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Circumstances') }} <span class="text-red-500">*</span>
                    </label>
                    <select name="circumstance_type"
                            class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="wandered"  @selected(old('circumstance_type')=='wandered')>{{ __('Wandered off') }}</option>
                        <option value="abducted"  @selected(old('circumstance_type')=='abducted')>{{ __('Suspected abduction') }}</option>
                        <option value="unknown"   @selected(old('circumstance_type')=='unknown')>{{ __('Unknown') }}</option>
                    </select>
                </div>
            </div>
        </fieldset>

        {{-- ── Description & contact ──────────────────────────────────── --}}
        <fieldset class="space-y-4">
            <legend class="font-semibold text-gray-700 border-b pb-1 w-full">
                {{ __('Additional Information') }}
            </legend>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    {{ __('Description') }} <span class="text-red-500">*</span>
                </label>
                <textarea name="description" rows="4" required
                          class="w-full border rounded-lg px-3 py-2 text-sm @error('description') border-red-400 @enderror"
                          placeholder="{{ __('Where exactly was the child last seen? Any details that could help.') }}">{{ old('description') }}</textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ __('Reporter Type') }} <span class="text-red-500">*</span>
                    </label>
                    <select name="reporter_type" class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="public"  @selected(old('reporter_type')=='public')>{{ __('Member of public') }}</option>
                        <option value="police"  @selected(old('reporter_type')=='police')>{{ __('Police') }}</option>
                        <option value="school"  @selected(old('reporter_type')=='school')>{{ __('School') }}</option>
                        <option value="ngo"     @selected(old('reporter_type')=='ngo')>{{ __('NGO') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Contact Phone') }}</label>
                    <input type="tel" name="contact_phone" value="{{ old('contact_phone', Auth::user()->phone ?? '') }}"
                           placeholder="+254 7XX XXX XXX"
                           class="w-full border rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __("Child's Photo") }}</label>
                <input type="file" name="photo" accept="image/*"
                       class="block w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-red-50 file:text-red-700 hover:file:bg-red-100">
                <p class="text-xs text-gray-400 mt-1">{{ __('JPG/PNG/WebP, max 10 MB') }}</p>
            </div>
        </fieldset>

        <button type="submit"
                class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg transition text-sm">
            {{ __('Submit Report') }}
        </button>
    </form>
</div>
@endsection

@push('scripts')
<script>
// Auto-fill lat/lng from county centroid lookup
const COUNTY_COORDS = {
    'Nairobi':    [-1.286389, 36.817223],
    'Mombasa':    [-4.043477, 39.668206],
    'Kisumu':     [-0.091702, 34.767956],
    'Nakuru':     [-0.303099, 36.080026],
    'Meru':       [ 0.046608, 37.649499],
    'Garissa':    [-0.453220, 39.646005],
    'Kakamega':   [ 0.282186, 34.751778],
    'Uasin Gishu': [0.514305, 35.269920],
    'Siaya':      [ 0.061389, 34.284167],
    'Kitui':      [-1.374167, 38.010833],
    'Machakos':   [-1.517669, 37.263417],
    'Kwale':      [-4.182000, 39.436000],
    'Bomet':      [-0.769444, 35.361389],
    'Embu':       [-0.537500, 37.457500],
    'Kajiado':    [-1.854167, 36.783333],
    'Samburu':    [ 0.534444, 37.528611],
    'Trans Nzoia':   [ 1.000000, 35.000000],
    'Vihiga':     [ 0.100000, 34.566667],
    'Marsabit':   [ 2.333333, 37.983333],
    'Meru':       [ 0.046608, 37.649499],
    'Murang\'a':  [-0.783333, 37.150000],
    'Tharaka-Nithi': [-0.333333, 37.750000],
    'West Pokot': [ 1.500000, 35.000000],
    'Isiolo':     [ 0.354167, 37.583333],
    'Lamu':       [-2.271111, 40.902500],
    'Nandi':      [ 0.100000, 35.100000],
    'Nyamira':    [-0.500000, 34.950000],
    'Nyandarua':  [-0.750000, 36.350000],
    'Nyeri':      [-0.416667, 36.950000],
    'Taita-Taveta': [-3.366667, 38.366667],
    'Tana River': [-1.000000, 40.000000],
    'Timau':      [-0.100000, 37.000000],
    'Turkana':    [ 3.119870, 35.597000],     

    // Add all 47 counties before production
};

document.getElementById('county-select').addEventListener('change', function () {
    const coords = COUNTY_COORDS[this.value];
    if (coords) {
        document.getElementById('lat-input').value = coords[0];
        document.getElementById('lng-input').value = coords[1];
    }
});
</script>
@endpush