@extends('layouts.app')
@section('title', 'Report a Missing Child — Kenya Amber Alert')

@section('content')
<div class="max-w-2xl mx-auto py-8 px-4">

    <a href="{{ route('home') }}" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1 mb-6">
        ← Back to map
    </a>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">🚨 Report a Missing Child</h1>
        <p class="text-gray-500 mt-1 text-sm">
            Fill in as many details as possible. Reports are reviewed by an officer before going live on the map.
            <strong>No account needed.</strong>
        </p>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-3 mb-6 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif

    <form method="POST" action="{{ route('cases.store') }}" enctype="multipart/form-data"
          class="bg-white rounded-xl shadow-sm border p-6 space-y-6">
        @csrf

        @if($errors->any())
        <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            <ul class="list-disc pl-4 space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
        @endif

        {{-- Child details --}}
        <fieldset class="space-y-4">
            <legend class="font-semibold text-gray-700 border-b pb-2 w-full text-sm uppercase tracking-wide">
                Child's Details
            </legend>

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Full Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="child_name" value="{{ old('child_name') }}" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none @error('child_name') border-red-400 @enderror">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Age <span class="text-red-500">*</span></label>
                    <input type="number" name="age" value="{{ old('age') }}" min="0" max="17" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gender <span class="text-red-500">*</span></label>
                    <select name="gender" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                        <option value="male"    @selected(old('gender')=='male')>Male</option>
                        <option value="female"  @selected(old('gender')=='female')>Female</option>
                        <option value="unknown" @selected(old('gender')=='unknown')>Unknown</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Height (cm)</label>
                    <input type="number" name="height_cm" value="{{ old('height_cm') }}" step="0.1" min="30" max="220"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none"
                        placeholder="e.g. 110">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Complexion</label>
                    <input type="text" name="complexion" value="{{ old('complexion') }}"
                        placeholder="e.g. Dark, Medium, Light"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Clothing <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="clothing" value="{{ old('clothing') }}" required
                        placeholder="e.g. Blue school uniform, white sandals"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none @error('clothing') border-red-400 @enderror">
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Distinctive Features</label>
                    <input type="text" name="distinctive" value="{{ old('distinctive') }}"
                        placeholder="Scars, birthmarks, disability — anything that helps identify"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                </div>
            </div>
        </fieldset>

        {{-- Last known location --}}
        <fieldset class="space-y-4">
            <legend class="font-semibold text-gray-700 border-b pb-2 w-full text-sm uppercase tracking-wide">
                Last Known Location
            </legend>

            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Specific Area <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="last_seen_area" value="{{ old('last_seen_area') }}" required
                        placeholder="e.g. Mathare Primary School gate, near Total petrol station"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none @error('last_seen_area') border-red-400 @enderror">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        County <span class="text-red-500">*</span>
                    </label>
                    <select name="county" id="county-select" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none @error('county') border-red-400 @enderror">
                        <option value="">Select county…</option>
                        @foreach(['Mombasa','Kwale','Kilifi','Tana River','Lamu','Taita-Taveta','Garissa','Wajir','Mandera','Marsabit','Isiolo','Meru','Tharaka-Nithi','Embu','Kitui','Machakos','Makueni','Nyandarua','Nyeri','Kirinyaga',"Murang'a",'Kiambu','Turkana','West Pokot','Samburu','Trans Nzoia','Uasin Gishu','Elgeyo-Marakwet','Nandi','Baringo','Laikipia','Nakuru','Narok','Kajiado','Kericho','Bomet','Kakamega','Vihiga','Bungoma','Busia','Siaya','Kisumu','Homa Bay','Migori','Kisii','Nyamira','Nairobi'] as $county)
                            <option value="{{ $county }}" @selected(old('county')===$county)>{{ $county }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sub-County</label>
                    <input type="text" name="sub_county" value="{{ old('sub_county') }}"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                </div>

                <input type="hidden" name="lat" id="lat-input" value="{{ old('lat', -1.286389) }}">
                <input type="hidden" name="lng" id="lng-input" value="{{ old('lng', 36.817223) }}">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Date & Time Last Seen <span class="text-red-500">*</span>
                    </label>
                    <input type="datetime-local" name="missing_since" value="{{ old('missing_since') }}"
                        max="{{ now()->format('Y-m-d\TH:i') }}" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none @error('missing_since') border-red-400 @enderror">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Circumstances</label>
                    <select name="circumstance_type"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                        <option value="wandered"  @selected(old('circumstance_type')=='wandered')>Wandered off</option>
                        <option value="abducted"  @selected(old('circumstance_type')=='abducted')>Suspected abduction</option>
                        <option value="unknown"   @selected(old('circumstance_type')=='unknown')>Unknown</option>
                    </select>
                </div>
            </div>
        </fieldset>

        {{-- Description & contact --}}
        <fieldset class="space-y-4">
            <legend class="font-semibold text-gray-700 border-b pb-2 w-full text-sm uppercase tracking-wide">
                Additional Information
            </legend>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Description <span class="text-red-500">*</span>
                </label>
                <textarea name="description" rows="3" required minlength="20"
                    placeholder="Where exactly was the child last seen? Any details that could help officers and the public identify them."
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none @error('description') border-red-400 @enderror">{{ old('description') }}</textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Your Contact Phone</label>
                    <input type="tel" name="contact_phone" value="{{ old('contact_phone') }}"
                        placeholder="+254 7XX XXX XXX"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                    <p class="text-xs text-gray-400 mt-1">For officer follow-up only, not shown publicly.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reporter Type</label>
                    <select name="reporter_type"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-500 focus:outline-none">
                        <option value="public"  @selected(old('reporter_type','public')=='public')>Member of public</option>
                        <option value="police"  @selected(old('reporter_type')=='police')>Police</option>
                        <option value="school"  @selected(old('reporter_type')=='school')>School</option>
                        <option value="ngo"     @selected(old('reporter_type')=='ngo')>NGO</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Child's Photo <span class="text-gray-400 font-normal">(recommended — helps officers verify)</span>
                </label>
                <input type="file" name="photo" accept="image/*"
                    class="block w-full text-sm text-gray-500
                           file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                           file:bg-red-50 file:text-red-700 hover:file:bg-red-100">
                <p class="text-xs text-gray-400 mt-1">JPG / PNG / WebP, max 10 MB</p>
            </div>
        </fieldset>

        {{-- Anti-spam notice --}}
        <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-xs text-amber-800">
            ⚠️ False reports are a criminal offence under Kenyan law. All reports are reviewed by an officer before going live.
            Your phone number and IP address are logged.
        </div>

        <button type="submit"
            class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg transition text-sm">
            Submit Report
        </button>
    </form>
</div>
@endsection

@push('scripts')
<script>
const COUNTY_COORDS = {
    'Nairobi':[-1.286389,36.817223],'Mombasa':[-4.043477,39.668206],
    'Kisumu':[-0.091702,34.767956],'Nakuru':[-0.303099,36.080026],
    'Meru':[0.046608,37.649499],'Garissa':[-0.453220,39.646005],
    'Kakamega':[0.282186,34.751778],'Turkana':[3.119870,35.597000],
    'Busia':[-0.460547,34.111523],'Siaya':[0.060480,34.288153],
    'Kisii':[-0.681762,34.776060],'Nyamira':[-0.566787,34.934654],
    'Homa Bay':[-0.526614,34.451338],'Migori':[-1.063565,34.473205],
    'Narok':[-1.101200,35.871900],'Kajiado':[-2.098800,36.776700],
    'Machakos':[-1.517300,37.263600],'Kitui':[-1.366700,38.016700],
    'Makueni':[-1.801400,37.620200],'Kiambu':[-1.031500,36.830900],
    'Murang\'a':[-0.717600,37.026000],'Kirinyaga':[-0.659200,37.380700],
    'Nyeri':[-0.421700,36.947400],'Nyandarua':[-0.181700,36.516200],
    'Laikipia':[0.361200,36.783000],'Samburu':[1.216200,36.700000],
    'Isiolo':[0.354300,37.582400],'Marsabit':[2.328200,37.994800],
    'Mandera':[3.936600,41.866900],'Wajir':[1.747800,40.058600],
    'Tana River':[-1.009800,39.668600],'Lamu':[-2.269600,40.902000],
    'Kilifi':[-3.629800,39.849800],'Kwale':[-4.173700,39.452000],
    'Taita-Taveta':[-3.316000,38.481000],'Embu':[-0.538900,37.457900],
    'Tharaka-Nithi':[-0.297800,37.907500],'Bomet':[-0.782000,35.342000],
    'Kericho':[-0.368800,35.286300],'Nandi':[0.183200,35.127500],
    'Trans Nzoia':[1.056300,34.950000],'Uasin Gishu':[0.552000,35.270000],
    'Elgeyo-Marakwet':[0.796000,35.478000],'West Pokot':[1.244000,35.112000],
    'Baringo':[0.666700,35.966700],'Vihiga':[0.070600,34.720000],
    'Bungoma':[0.563400,34.559500],
};
document.getElementById('county-select').addEventListener('change', function() {
    const c = COUNTY_COORDS[this.value];
    if (c) {
        document.getElementById('lat-input').value = c[0];
        document.getElementById('lng-input').value = c[1];
    }
});
</script>
@endpush