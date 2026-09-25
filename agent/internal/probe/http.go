package probe

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/mirivlad/mirvmon/agent/internal/config"
	"github.com/mirivlad/mirvmon/agent/internal/protocol"
)

const concurrency = 8

type HTTPExecutor struct {
	now func() time.Time
}

func NewHTTPExecutor() *HTTPExecutor {
	return &HTTPExecutor{now: time.Now}
}

func (executor *HTTPExecutor) Execute(ctx context.Context, jobs []config.ProbeJob) []protocol.ProbeResult {
	results := make([]protocol.ProbeResult, len(jobs))
	sem := make(chan struct{}, concurrency)
	var wait sync.WaitGroup
	for index, job := range jobs {
		index, job := index, job
		wait.Add(1)
		go func() {
			defer wait.Done()
			select {
			case sem <- struct{}{}:
				defer func() { <-sem }()
			case <-ctx.Done():
				results[index] = executor.failure(job, executor.now().UTC(), 0, "timeout", "Probe cancelled.")
				return
			}
			results[index] = executor.check(ctx, job)
		}()
	}
	wait.Wait()
	return results
}
func (executor *HTTPExecutor) check(parent context.Context, job config.ProbeJob) protocol.ProbeResult {
	start := executor.now().UTC()
	ctx, cancel := context.WithTimeout(parent, time.Duration(job.TimeoutSeconds)*time.Second)
	defer cancel()

	client := &http.Client{
		Transport: http.DefaultTransport.(*http.Transport).Clone(),
	}
	client.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if !job.FollowRedirects {
			return http.ErrUseLastResponse
		}
		if len(via) > job.MaxRedirects {
			return errors.New("probe redirect limit")
		}
		return nil
	}

	request, err := http.NewRequestWithContext(ctx, job.Method, job.URL, nil)
	if err != nil {
		return executor.failure(job, start, 0, "internal_checker", "Invalid probe request.")
	}
	request.Header.Set("User-Agent", "MirvMon-Agent distributed-probe")
	response, err := client.Do(request)
	if err != nil {
		kind, message := classify(err)
		return executor.failure(job, start, elapsedMS(start, executor.now().UTC()), kind, message)
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 64*1024))

	status := response.StatusCode
	return protocol.ProbeResult{
		WebsiteID:   job.WebsiteID,
		EndpointID:  job.EndpointID,
		ObservedAt:  start.Format(time.RFC3339),
		Available:   true,
		StatusCode:  &status,
		TotalMS:     elapsedMS(start, executor.now().UTC()),
		ErrorKind:   "",
		SafeMessage: "HTTP response received.",
	}
}

func (executor *HTTPExecutor) failure(job config.ProbeJob, observed time.Time, total float64, kind, message string) protocol.ProbeResult {
	return protocol.ProbeResult{
		WebsiteID: job.WebsiteID, EndpointID: job.EndpointID,
		ObservedAt: observed.Format(time.RFC3339), Available: false,
		StatusCode: nil, TotalMS: total, ErrorKind: kind, SafeMessage: message,
	}
}

func elapsedMS(start, end time.Time) float64 {
	value := float64(end.Sub(start).Microseconds()) / 1000
	if value < 0 {
		return 0
	}
	return value
}

func classify(err error) (string, string) {
	if errors.Is(err, context.DeadlineExceeded) {
		return "timeout", "Request timed out."
	}
	var urlError *url.Error
	if errors.As(err, &urlError) {
		if urlError.Timeout() {
			return "timeout", "Request timed out."
		}
		var dnsError *net.DNSError
		if errors.As(urlError.Err, &dnsError) {
			return "dns", "DNS lookup failed."
		}
		var certificateError *tls.CertificateVerificationError
		if errors.As(urlError.Err, &certificateError) {
			return "tls", "TLS verification failed."
		}
		var unknownAuthority x509.UnknownAuthorityError
		if errors.As(urlError.Err, &unknownAuthority) {
			return "tls", "TLS verification failed."
		}
		if strings.Contains(urlError.Err.Error(), "probe redirect limit") {
			return "redirect_limit", "Redirect limit exceeded."
		}
	}
	return "connect", "Connection failed."
}
