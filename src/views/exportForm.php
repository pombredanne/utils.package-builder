<?php /* @var $this Mouf\Utils\PackageBuilder\Expor\ExportController */ ?>

<h1>Export instances</h1>

<p>Want to generate PHP code to create instance in one of your installers? You can export your existing
instances into PHP code. In the form below, fill the list of instances you want to export (one per line).
Anonymous instances attached to your instances will also be exported.</p>

<form>
	<label>Instances list</label>
	<textarea type="text" name="instances"><?php echo $this->instances; ?></textarea>
	<span class="help-block">One instance name per line.</span>

	<button type="submit" class="btn btn-primary">Generate PHP code</button>
</form>

<?php if ($this->generatedCode): ?>
<h2>Generated export code</h2>

<pre><code><?php echo htmlentities($this->generatedCode, ENT_QUOTES, 'utf-8'); ?></code></pre>
<?php endif; ?>