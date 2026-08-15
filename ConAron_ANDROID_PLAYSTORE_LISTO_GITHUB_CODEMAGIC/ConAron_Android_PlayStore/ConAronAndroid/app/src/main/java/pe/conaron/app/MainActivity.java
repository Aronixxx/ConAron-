package pe.conaron.app;

import android.Manifest;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.view.View;
import android.view.WindowInsets;
import android.webkit.GeolocationPermissions;
import android.webkit.SslErrorHandler;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.net.http.SslError;
import android.widget.FrameLayout;
import android.widget.Toast;

import java.util.Locale;

public class MainActivity extends Activity {
    private static final int LOCATION_REQUEST = 2001;
    private static final int FILE_CHOOSER_REQUEST = 2002;

    private WebView webView;
    private ValueCallback<Uri[]> fileChooserCallback;
    private GeolocationPermissions.Callback geolocationCallback;
    private String geolocationOrigin;
    private String appUrl;
    private String appHost;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        appUrl = getString(R.string.app_url);
        appHost = Uri.parse(appUrl).getHost();

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.WHITE);
        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT));
        setContentView(root);

        applySystemInsets(root);
        configureWebView();
        handleIntent(getIntent());

        if (savedInstanceState == null && !isOAuthDeepLink(getIntent())) {
            webView.loadUrl(appUrl);
        } else if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState);
        }
    }

    private void applySystemInsets(View root) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            root.setOnApplyWindowInsetsListener((v, insets) -> {
                android.graphics.Insets bars = insets.getInsets(WindowInsets.Type.systemBars());
                v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
                return insets;
            });
        } else {
            root.setOnApplyWindowInsetsListener((v, insets) -> {
                v.setPadding(
                        insets.getSystemWindowInsetLeft(),
                        insets.getSystemWindowInsetTop(),
                        insets.getSystemWindowInsetRight(),
                        insets.getSystemWindowInsetBottom());
                return insets;
            });
        }
    }

    private void configureWebView() {
        android.webkit.CookieManager cookieManager = android.webkit.CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(webView, true);

        webView.getSettings().setJavaScriptEnabled(true);
        webView.getSettings().setDomStorageEnabled(true);
        webView.getSettings().setDatabaseEnabled(true);
        webView.getSettings().setGeolocationEnabled(true);
        webView.getSettings().setMediaPlaybackRequiresUserGesture(false);
        webView.getSettings().setLoadWithOverviewMode(true);
        webView.getSettings().setUseWideViewPort(true);
        webView.getSettings().setBuiltInZoomControls(false);
        webView.getSettings().setDisplayZoomControls(false);
        webView.getSettings().setUserAgentString(
                webView.getSettings().getUserAgentString() + " ConAronAndroid/1.0");

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onGeolocationPermissionsShowPrompt(
                    String origin,
                    GeolocationPermissions.Callback callback) {
                geolocationOrigin = origin;
                geolocationCallback = callback;
                if (hasLocationPermission()) {
                    callback.invoke(origin, true, false);
                    geolocationCallback = null;
                    geolocationOrigin = null;
                } else {
                    requestPermissions(new String[]{
                            Manifest.permission.ACCESS_FINE_LOCATION,
                            Manifest.permission.ACCESS_COARSE_LOCATION
                    }, LOCATION_REQUEST);
                }
            }

            @Override
            public boolean onShowFileChooser(
                    WebView webView,
                    ValueCallback<Uri[]> filePathCallback,
                    FileChooserParams fileChooserParams) {
                if (fileChooserCallback != null) fileChooserCallback.onReceiveValue(null);
                fileChooserCallback = filePathCallback;

                Intent intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                intent.setType("image/*");
                intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, false);
                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST);
                } catch (ActivityNotFoundException e) {
                    fileChooserCallback = null;
                    Toast.makeText(MainActivity.this, "No se encontró un selector de imágenes.", Toast.LENGTH_LONG).show();
                    return false;
                }
                return true;
            }
        });

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return handleNavigation(request.getUrl());
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return handleNavigation(Uri.parse(url));
            }

            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.cancel();
                Toast.makeText(MainActivity.this, "Conexión segura no válida.", Toast.LENGTH_LONG).show();
            }
        });

        webView.setDownloadListener((url, userAgent, contentDisposition, mimetype, contentLength) -> {
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
            } catch (Exception ignored) {
                Toast.makeText(this, "No se pudo abrir la descarga.", Toast.LENGTH_SHORT).show();
            }
        });
    }

    private boolean handleNavigation(Uri uri) {
        if (uri == null || uri.getScheme() == null) return false;
        String scheme = uri.getScheme().toLowerCase(Locale.ROOT);

        if ("conaron".equals(scheme)) {
            handleOAuthUri(uri);
            return true;
        }
        if ("tel".equals(scheme)) {
            openExternal(new Intent(Intent.ACTION_DIAL, uri));
            return true;
        }
        if ("mailto".equals(scheme)) {
            openExternal(new Intent(Intent.ACTION_SENDTO, uri));
            return true;
        }

        if ("http".equals(scheme) || "https".equals(scheme)) {
            String host = uri.getHost();
            String path = uri.getPath() == null ? "" : uri.getPath();

            if (sameHost(host) && path.endsWith("/google_login.php")) {
                Uri androidLogin = uri.buildUpon()
                        .appendQueryParameter("app", "android")
                        .build();
                openExternal(new Intent(Intent.ACTION_VIEW, androidLogin));
                return true;
            }

            if (sameHost(host)) return false;

            openExternal(new Intent(Intent.ACTION_VIEW, uri));
            return true;
        }

        openExternal(new Intent(Intent.ACTION_VIEW, uri));
        return true;
    }

    private boolean sameHost(String host) {
        return host != null && appHost != null && host.equalsIgnoreCase(appHost);
    }

    private void openExternal(Intent intent) {
        try {
            startActivity(intent);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(this, "No hay una aplicación disponible para abrir esta opción.", Toast.LENGTH_SHORT).show();
        }
    }

    private boolean hasLocationPermission() {
        return checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == LOCATION_REQUEST && geolocationCallback != null && geolocationOrigin != null) {
            boolean granted = hasLocationPermission();
            geolocationCallback.invoke(geolocationOrigin, granted, false);
            geolocationCallback = null;
            geolocationOrigin = null;
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != FILE_CHOOSER_REQUEST || fileChooserCallback == null) return;

        Uri[] result = null;
        if (resultCode == RESULT_OK && data != null && data.getData() != null) {
            result = new Uri[]{data.getData()};
        }
        fileChooserCallback.onReceiveValue(result);
        fileChooserCallback = null;
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        handleIntent(intent);
    }

    private boolean isOAuthDeepLink(Intent intent) {
        Uri data = intent == null ? null : intent.getData();
        return data != null && "conaron".equalsIgnoreCase(data.getScheme())
                && "oauth".equalsIgnoreCase(data.getHost());
    }

    private void handleIntent(Intent intent) {
        Uri data = intent == null ? null : intent.getData();
        if (data != null && "conaron".equalsIgnoreCase(data.getScheme())
                && "oauth".equalsIgnoreCase(data.getHost())) {
            handleOAuthUri(data);
        }
    }

    private void handleOAuthUri(Uri uri) {
        String token = uri.getQueryParameter("token");
        String error = uri.getQueryParameter("error");

        if (error != null && !error.isEmpty()) {
            webView.loadUrl(appUrl + "?google_error=" + Uri.encode(error));
            return;
        }
        if (token == null || token.isEmpty()) {
            webView.loadUrl(appUrl + "?google_error=" + Uri.encode("Google no devolvió el acceso a ConAron."));
            return;
        }
        webView.loadUrl(appUrl + "android_login.php?token=" + Uri.encode(token));
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        webView.saveState(outState);
        super.onSaveInstanceState(outState);
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
